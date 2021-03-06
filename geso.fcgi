#!/usr/bin/env perl

use strict;
use warnings;
use locale;

#-------------------------------------------------------------------------------
# Player

package Geso::Player;

use File::Spec;
use IO::Handle;
use String::ShellQuote qw(shell_quote);
use POSIX ":sys_wait_h";

use constant {
	OFF => 'off',
	PLAYING => 'playing',
	PAUSED => 'paused',
};

our %state = (
	status => OFF
);

sub command {
	return if $state{status} eq OFF;
	my $fh = $state{in};
	print $fh (shift . "\n");
	$fh->flush();
}

sub reset_state {
	close $state{in};
	$state{status} = OFF;
	delete $state{pid};
}

sub spawn {
	stop() if $state{status} ne OFF;
	delete $state{youtube};
	delete $state{title};
	my $arg = shift;
	my $command = shell_quote('mpv', "--config-dir=$ENV{DOCUMENT_ROOT}/.mpv", '--really-quiet', '--no-input-terminal', '--input-file=/dev/stdin', '--');
	unless ($arg =~ /^[a-z]+:\/\//) {
		my ($vol, $dir, $file) = File::Spec->splitpath($arg);
		my $play = File::Spec->catpath($vol, $dir, '.play');
		$command = $play if -x $play;
	}
	$state{file} = $arg;
	$state{pid} = open($state{in}, '|-', $command . ' ' . shell_quote($arg) . ' > /dev/null');
	$state{status} = PLAYING;
}

sub update {
	return if $state{status} eq OFF;
	my $kid = waitpid($state{pid}, WNOHANG);
	reset_state() if $kid > 0;
}

sub stop {
	return if $state{status} eq OFF;
	command('quit');
	my $kid = waitpid($state{pid}, 0);
	reset_state() if $kid > 0;
}

sub play {
	return if $state{status} eq OFF;
	command('pause') if $state{status} eq PAUSED;
	$state{status} = PLAYING;
}

sub pause {
	return if $state{status} eq OFF;
	command('pause') if $state{status} eq PLAYING;
	$state{status} = PAUSED;
}

#-------------------------------------------------------------------------------
# YouTube

package Geso::YouTube::Parser;

use HTML::Parser ();

use constant {
	OUT => 0,
	TIME => 1,
	TITLE => 2,
	USER => 3,
	USERNAME => 4,
	META => 5,
	DESCRIPTION => 6,
};

my $location = OUT;
my $video = {};
my $videos = [];

sub start {
	my ($tagname, $attr) = @_;
	my $class = $attr->{class};
	return unless $class;
	if ($location eq OUT) {
		if ($class eq 'video-time') {
			$location = TIME;
		} elsif ($class eq 'yt-lockup-title ') {
			$location = TITLE;
		} elsif ($class eq 'yt-lockup-byline') {
			$location = USER;
		} elsif ($class eq 'yt-lockup-meta-info') {
			$location = META;
		} elsif ($class eq 'yt-lockup-description yt-ui-ellipsis yt-ui-ellipsis-2') {
			$location = DESCRIPTION;
		}
	} elsif ($tagname eq 'a') {
		my $href = $attr->{href};
		my $title = $attr->{title};
		return unless $href;
		if ($location eq TITLE) {
			if ($title && $href =~ /^\/watch\?v=([^&]+)/) {
				$video->{title} = $title;
				$video->{id} = $1;
				$video->{thumbnail} = "//i.ytimg.com/vi/$1/mqdefault.jpg";
			}
		} elsif ($location eq USER) {
			$location = USERNAME;
		}
	}
}

sub end {
	my ($tagname) = @_;
	if ($location eq META && $tagname eq 'li') {
		# move on to the next element, duration
	} else {
		commit() if $location eq DESCRIPTION;
		$location = OUT;
	}
}

sub text {
	my ($text) = @_;
	if ($location eq TIME) {
		$video->{time} = $text;
	} elsif ($location eq META) {
		if ($text =~ /^\d/) {
			my @seqs = $text =~ /(\d+)/g;
			$video->{views} = join('', @seqs);
		}
	} elsif ($location eq DESCRIPTION) {
		$video->{description} = $video->{description} ? $video->{description} . $text : $text;
	} elsif ($location eq USERNAME) {
		$video->{user} = $text;
	}
}

sub commit {
	push @$videos, $video if $video->{id};
	$video = {};
}

sub parse {
	my $in = shift;
	my $p = HTML::Parser->new(
		api_version => 3,
		start_h => [\&start, 'tagname, attr'],
		end_h => [\&end, 'tagname'],
		text_h => [\&text, 'text'],
	);
	$p->unbroken_text(1);
	$p->parse($in);
	my $result = $videos;
	$videos = [];
	return @$result;
}

package Geso::YouTube;

use File::Spec::Functions;
use LWP::UserAgent ();
use String::ShellQuote qw(shell_quote);
use URI::Escape qw(uri_escape);
use POSIX ":sys_wait_h";

use constant {
	DOWNLOADING => 'downloading',
	DONE => 'done',
	CANCELED => 'canceled',
	FAILED => 'failed',
};

our %downloads = ();

sub download {
	my ($id, $name) = @_;
	if ($downloads{$id}) {
		my $status = $downloads{$id}->{status};
		return if $status eq DOWNLOADING or $status eq DONE;
	}
	if (my $pid = fork()) {
		$downloads{$id} = { pid => $pid, name => $name, status => DOWNLOADING };
	} else {
		my $output = catfile($ENV{DOCUMENT_ROOT}, 'youtube', '%(title)s.%(id)s.%(ext)s');
		exec('youtube-dl', '--quiet', '--output', $output, '--', $id);
	}
}

sub update {
	foreach (keys %downloads) {
		my $dl = $downloads{$_};
		next if $dl->{status} ne DOWNLOADING;
		my $kid = waitpid($dl->{pid}, WNOHANG);
		next unless $kid > 0;
		$dl->{status} = $? == 0 ? DONE : FAILED;
	}
}

sub cancel {
	my ($id) = @_;
	my $dl = $downloads{$id} or return;
	return if $dl->{status} ne DOWNLOADING;
	kill 'TERM', $dl->{pid};
	waitpid($dl->{pid}, 0);
	$dl->{status} = CANCELED;
}

sub clear {
	my ($id) = @_;
	my $dl = $downloads{$id} or return;
	return if $dl->{status} eq DOWNLOADING;
	delete $downloads{$id};
}

sub search {
	my ($query) = @_;
	my $url = 'https://www.youtube.com/results?search_query=' . uri_escape($query) . '&gl=US';
	# gl=US is worldwide according to YouTube
	my $ua = LWP::UserAgent->new;
	my $res = $ua->get($url);
	if ($res->is_success && $res->code == 200) {
		return Geso::YouTube::Parser::parse($res->content);
	}
}

sub init {
	# Find the canceled downloads.
	foreach (glob catfile($ENV{DOCUMENT_ROOT}, 'youtube', '*.part')) {
		my ($vol, $dir, $file) = File::Spec->splitpath($_);
		if ($file =~ /^(.+)\.([a-zA-Z0-9_\-]+)\.[^.]+\.part$/) {
			$downloads{$2} = { name => $1, status => CANCELED };
		}
	}
}

sub get {
	my ($id) = @_;
	open(my $out, '-|', shell_quote('youtube-dl', '--get-title', '--get-url', '--', $id));
	my ($title, $url) = <$out>;
	close $out;
	chomp ($title, $url);
	return ($title, $url);
}

sub play {
	my ($id) = @_;
	my ($title, $url) = Geso::YouTube::get($id);
	Geso::Player::spawn($url);
	delete $Geso::Player::state{file};
	$Geso::Player::state{youtube} = $id;
	$Geso::Player::state{title} = $title;
}

#-------------------------------------------------------------------------------
# HTML

package Geso::HTML;

use CGI qw(escapeHTML);
use Encode qw(encode_utf8 decode_utf8);
use File::Spec::Functions;
use URI::Escape qw(uri_escape);

sub header {
	my $title = shift;
	print CGI::header(-type => 'text/html', -charset => 'utf-8', @_);
	my $html_title = $title ? escapeHTML($title) . ' - Geso' : 'Geso';
	print <<"EOF";
<!doctype html>
<html>
	<head>
		<title>$html_title</title>
		<meta charset="utf-8" />
		<meta name="robots" content="noindex, nofollow" />
		<meta name="viewport" content="width=500" />
		<link rel="stylesheet" type="text/css" href="/geso.css" />
		<script>
			function update(info) {
				document.getElementById("status").innerHTML = info.status;
				document.getElementById("file").innerHTML = info.file;
				document.getElementById("state").className = info.status;
			}
			function call(url) {
				var req = new XMLHttpRequest();
				req.onreadystatechange = function() {
					if (req.readyState == 4 && req.status == 200) {
						var result = JSON.parse(req.responseText);
						update(result);
					}
				}
				req.open("GET", url);
				req.send();
			}
			function dynamize() {
				var links = document.getElementsByClassName("api");
				for (var i = 0; i < links.length; i++) {
					links[i].addEventListener('click', function(e) {
						var url = this.href;
						if (url.indexOf("?") == -1)
							url += "?api=json";
						else
							url += "&api=json";
						call(url);
						e.preventDefault();
					});
				}
			}
		</script>
	</head>
	<body onload="dynamize();">
EOF
	status();
	menu($title);
}

sub status {
	my $file = $Geso::Player::state{file};
	if ($file) {
		$file = File::Spec->abs2rel($file, $ENV{DOCUMENT_ROOT});
		$file =~ s|/| / |g;
		$file = escapeHTML($file);
	} else {
		$file = $Geso::Player::state{title};
		unless ($file) { $file = ''; }
	}
	print <<"EOF";
	<div id="state" class="$Geso::Player::state{status}">
		<div class="statusline">
			<span id="status">$Geso::Player::state{status}</span>
			<span id="file">$file</span>
		</div>
		<div class="actions">
			<span class="section">
				<b>Play</b>
				<a href="/play" class="api play" title="Play">Play</a>
				<a href="/pause" class="api pause on" title="Pause">Pause</a>
				<a href="/stop" class="api on" title="Stop">Stop</a>
			</span>
			<span class="section">
				<b>Seek</b>
				<a href="/seek?time=-300" class="api on" title="Rewind 5 minutes">-5m</a>
				<a href="/seek?time=-30" class="api on" title="Rewind 30 seconds">-30s</a>
				<a href="/seek?time=30" class="api on" title="Forward 30 seconds">+30s</a>
				<a href="/seek?time=300" class="api on" title="Forward 5 minutes">+5m</a>
			</span>
		</div>
	</div>
EOF
}

sub youtube_status {
	print '<table class="ytdl">';
	foreach (keys %Geso::YouTube::downloads) {
		my $dl = $Geso::YouTube::downloads{$_};
		print "<tr class=\"$dl->{status}\">"
		. '<td class="name">' . escapeHTML($dl->{name}) . '</td>'
		. '<td class="status">' . $dl->{status} . '</td>'
		. '<td class="do">';
		print " <a href=\"/youtube/cancel?v=$_\">Cancel</a>" if $dl->{status} eq Geso::YouTube::DOWNLOADING;
		print " <a href=\"/youtube/download?v=$_\">Restart</a>" if $dl->{status} eq Geso::YouTube::CANCELED || $dl->{status} eq Geso::YouTube::FAILED;
		print " <a href=\"/youtube/play?v=$_\" class=\"api\">Play</a>" if $dl->{status} eq Geso::YouTube::DONE;
		print '</td></tr>';
	}
	print '</table>';
}

my %menu_titles = (
	'/' => 'Status',
	'/library' => 'Library',
);

sub menu {
	my $url = CGI::url(-absolute => 1, -query => 1);
	my $title = shift;
	my $found = 0;
	print '<ul class="menu">';
	foreach ('/', '/library') {
		if ($_ eq $url) {
			print '<li class="current">';
			$found = 1;
		} else {
			print '<li>';
		}
		print "<a href=\"$_\">$menu_titles{$_}</a></li>";
	}
	if (!$found && $title) {
		print '<li class="current"><a>'
		. escapeHTML($title)
		. '</a></li>';
	}
	print <<EOF;
		<li class="youtube">
			<form action="/youtube/search">
				<input type="text" name="q" placeholder="YouTube" />
				<input type="submit" value="Search" />
			</form>
		</li>
	</ul>
EOF
}

sub footer {
	print<<EOF;
	</body>
</html>
EOF
}

sub traverse {
	print '<ul>';
	my ($root, $base) = @_;
	opendir(my $dh, catdir($root, $base));
	my @entries = sort readdir($dh);
	closedir($dh);
	foreach (@entries) {
		next if /^\./;
		my $path = catfile($root, $base, $_);
		if (-d $path) {
			print '<li class="dir"><span>' . escapeHTML($_) . '</span>';
			traverse($root, catdir($base, $_));
			print '</li>';
		} elsif (-f $path) {
			my $url = '/spawn?file=' . escapeHTML(uri_escape(catfile($base, $_)));
			print "<li class=\"file\"><a href=\"$url\" class=\"api\">" . escapeHTML($_) . '</a></li>';
		}
	}
	print '</ul>';
}

sub commands {
	print <<EOF;
	<ul class="commands">
		<li><a href="/seek?time=-1800" class="api">Seek -30 minutes.</a></li>
		<li><a href="/seek?time=1800" class="api">Seek +30 minutes.</a></li>
		<li><a href="/chapter?seek=-1" class="api">Previous chapter.</a></li>
		<li><a href="/chapter?seek=1" class="api">Next chapter.</a></li>
		<li><a href="/playlist/prev" class="api">Previous video.</a></li>
		<li><a href="/playlist/next" class="api">Next video.</a></li>
		<li><a href="/audio?cycle=up" class="api">Cycle audio tracks.</a></li>
		<li><a href="/subtitles?cycle=up" class="api">Cycle subtitles.</a></li>
	</ul>
EOF
}

#-------------------------------------------------------------------------------
# Actions

package Geso::Actions;

use File::Spec::Functions;
use JSON;

sub forbidden {
	my ($msg) = @_;
	print CGI::header(-type => 'text/plain', -charset => 'utf-8', -status => '403 Forbidden');
	print "403 Forbidden\n$msg\n";
}

sub api_status {
	print CGI::header(-type => 'application/json', -charset => 'utf-8');
	my $file = $Geso::Player::state{file};
	if ($file) {
		$file = File::Spec->abs2rel($file, $ENV{DOCUMENT_ROOT});
		$file =~ s|/| / |g;
	}
	print to_json {
		status => $Geso::Player::state{status},
		file => $file ? $file : $Geso::Player::state{title},
	};
}

sub feedback {
	if (CGI::param('api')) {
		api_status();
	} else {
		print CGI::redirect('/');
	}
}

sub play {
	if ($Geso::Player::state{status} eq Geso::Player::OFF) {
		my $f = $Geso::Player::state{file};
		my $yt = $Geso::Player::state{youtube};
		if ($f) {
			Geso::Player::spawn($f);
		} elsif ($yt) {
			Geso::YouTube::play($yt);
		}
	} else {
		Geso::Player::play();
	}
	feedback();
}

sub pause {
	Geso::Player::pause();
	feedback();
}

sub play_pause {
	if ($Geso::Player::state{status} eq Geso::Player::PLAYING) {
		pause();
	} else {
		play();
	}
}

sub stop {
	Geso::Player::stop();
	feedback();
}

sub spawn {
	# http://www.perlmonks.org/?node=Sanitizing%20user-provided%20path%2Ffilenames
	my $file = CGI::param('file');
	my $root = $ENV{DOCUMENT_ROOT};
	my $abs = File::Spec->rel2abs($file, $root);
	if ($abs =~ /^\Q$root/) {
		my $root = $ENV{DOCUMENT_ROOT};
		Geso::Player::spawn(catfile($root, $file));
		feedback();
	} else {
		forbidden("Shady path.");
	}
}

sub seek {
	my $time = CGI::param('time');
	if ($time =~ /^[-+]?\d+$/) {
		Geso::Player::command("seek $time");
		feedback();
	} else {
		forbidden("Invalid time $time.");
	}
}

sub chapter {
	my $seek = CGI::param('seek');
	if ($seek =~ /^[-+]?\d+$/) {
		Geso::Player::command("add chapter $seek");
		feedback();
	} else {
		forbidden("Invalid direction $seek.");
	}
}

sub audio {
	my $cycle = CGI::param('cycle');
	if ($cycle eq 'up' || $cycle eq 'down') {
		Geso::Player::command("cycle audio $cycle");
		feedback();
	} else {
		forbidden('Invalid audio selection.');
	}
}

sub subtitles {
	my $cycle = CGI::param('cycle');
	if ($cycle eq 'up' || $cycle eq 'down') {
		Geso::Player::command("cycle sub $cycle");
		feedback();
	} else {
		forbidden('Invalid subtitles selection.');
	}
}

sub playlist_next {
	Geso::Player::command("playlist-next force");
	feedback();
}

sub playlist_prev {
	Geso::Player::command("playlist-prev force");
	feedback();
}

sub youtube_cancel {
	my $id = CGI::param('v');
	Geso::YouTube::cancel($id) if $id;
	feedback();
}

sub youtube_clear {
	my $id = CGI::param('v');
	Geso::YouTube::clear($id) if $id;
	feedback();
}

sub youtube_download {
	my $id = CGI::param('v');
	my $name = CGI::param('name');
	my $dl = $Geso::YouTube::downloads{$id};
	if (!$name && $dl) {
		$name = $dl->{name};
	}
	Geso::YouTube::download($id, $name) if $id and $name;
	feedback();
}

sub youtube_play {
	my $id = CGI::param('v');
	my $current = $Geso::Player::state{youtube};
	if ($current && $current eq $id) {
		Geso::Player::play();
		feedback();
		return;
	}
	my @files = glob catfile($ENV{DOCUMENT_ROOT}, 'youtube', "*.$id.*");
	if (@files) {
		Geso::Player::spawn(shift @files);
		Geso::YouTube::clear($id);
	} else {
		Geso::YouTube::play($id);
	}
	feedback();
}

#-------------------------------------------------------------------------------
# Pages

package Geso::Pages;

use CGI qw(escapeHTML);
use URI::Escape qw(uri_escape);

sub status {
	if (CGI::param('api')) {
		return Geso::Actions::api_status();
	}
	Geso::HTML::header();
	print '<h2>YouTube</h2>';
	Geso::HTML::youtube_status();
	print '<h2>Advanced commands</h2>';
	Geso::HTML::commands();
	Geso::HTML::footer();
}

sub library {
	Geso::HTML::header('Library');
	print '<h2>Library</h2>'
	. '<div class="library">';
	my $root = $ENV{DOCUMENT_ROOT};
	Geso::HTML::traverse($root, '.');
	print '</div>';
	Geso::HTML::footer();
}

sub youtube_search {
	my $query = CGI::param('q') or return print CGI::redirect('/');
	Geso::HTML::header("YouTube: $query");
	print '<h2>YouTube: ' . escapeHTML($query) . '</h2>';
	print '<ul class="youtube">';
	foreach (Geso::YouTube::search($query)) {
		my $dw_url = "/youtube/download?v=$_->{id}&name=" . escapeHTML(uri_escape($_->{title}));
		if ($Geso::YouTube::downloads{$_->{id}}) {
			print '<li class="running">';
		} else {
			print '<li>';
		}
		my $views = $_->{views} =~ s/(?<=\d)(?=(?:\d\d\d)+\b)/\&nbsp;/gr;
		print "<a class=\"api\" href=\"/youtube/play?v=$_->{id}\" onclick=\"this.parentNode.className='playing';\">"
		. "<img src=\"$_->{thumbnail}\" /></a>"
		. "<a class=\"api title\" href=\"$dw_url\" onclick=\"this.parentNode.className='running';\">"
		. escapeHTML($_->{title}) . '</a> '
		. "<div class=\"meta\">by $_->{user}. Duration: $_->{time}. $views views.</div>"
		. "<div class=\"description\">$_->{description}</div>"
		. '<div style="clear:both;"></div>'
		. '</li>';
	}
	print '</ul>';
}

#-------------------------------------------------------------------------------
# Main

package main;

use CGI ();
use CGI::Fast ();

my %pages = (
	'/' => \&Geso::Pages::status,
	'/library' => \&Geso::Pages::library,
	'/play' => \&Geso::Actions::play,
	'/pause' => \&Geso::Actions::pause,
	'/playpause' => \&Geso::Actions::play_pause,
	'/stop' => \&Geso::Actions::stop,
	'/spawn' => \&Geso::Actions::spawn,
	'/seek' => \&Geso::Actions::seek,
	'/chapter' => \&Geso::Actions::chapter,
	'/audio' => \&Geso::Actions::audio,
	'/subtitles' => \&Geso::Actions::subtitles,
	'/playlist/next' => \&Geso::Actions::playlist_next,
	'/playlist/prev' => \&Geso::Actions::playlist_prev,
	'/youtube/search' => \&Geso::Pages::youtube_search,
	'/youtube/cancel' => \&Geso::Actions::youtube_cancel,
	'/youtube/clear' => \&Geso::Actions::youtube_clear,
	'/youtube/download' => \&Geso::Actions::youtube_download,
	'/youtube/play' => \&Geso::Actions::youtube_play,
);

sub route {
	my $url = CGI::url(-absolute => 1);
	my $page = $pages{$url};
	if ($page) {
		$page->();
	} else {
		print CGI::header(-type => 'text/plain', -charset => 'utf-8', -status => '404 Not Found');
		print "404 Not Found\n";
	}
}

my %base_env = %ENV;
my $did_init;

while (new CGI::Fast) {
	$ENV{$_} = $base_env{$_} foreach keys %base_env;
	unless ($did_init) {
		Geso::YouTube::init();
		$did_init = 1;
	}
	Geso::Player::update();
	Geso::YouTube::update();
	route();
}
