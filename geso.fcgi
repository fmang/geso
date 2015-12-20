#!/usr/bin/env perl

use strict;
use warnings;
use locale;

#-------------------------------------------------------------------------------
# Player

package Geso::Player;

use IO::Handle;
use String::ShellQuote qw(shell_quote);
use POSIX;

use constant {
	OFF => 'Off',
	PLAYING => 'Playing',
	PAUSED => 'Paused',
};

our %state = (
	status => OFF
);

sub command {
	return if $state{status} eq OFF;
	my $fh = $state{in};
	print $fh shift;
	$fh->flush();
}

sub reset_state {
	close $state{in};
	$state{status} = OFF;
	delete $state{pid};
}

sub spawn {
	stop() if $state{status} ne OFF;
	my $arg = shift;
	$state{file} = $arg;
	if ($arg =~ /^-/) { $arg = "./$arg"; }
	$state{pid} = open($state{in}, '|-', shell_quote('omxplayer', $arg));
	$state{status} = PLAYING;
}

sub update {
	return if $state{status} eq OFF;
	my $kid = waitpid($state{pid}, WNOHANG);
	reset_state() if $kid > 0;
}

sub stop {
	return if $state{status} eq OFF;
	command('q');
	my $kid = waitpid($state{pid}, 0);
	reset_state() if $kid > 0;
}

sub playpause {
	return if $state{status} eq OFF;
	command(' ');
	$state{status} = $state{status} eq PLAYING ? PAUSED : PLAYING;
}

sub seek {
	return if $state{status} eq OFF;
	use integer;
	my $time = shift;
	my $abstime = abs($time);
	my $big_steps = $abstime / 600;
	my $small_steps = ($abstime % 600) / 30;
	if ($time >= 30) {
		command(("\027[A" x $big_steps) . ("\027[C" x $small_steps));
	} elsif ($time <= -30) {
		command(("\027[B" x $big_steps) . ("\027[D" x $small_steps));
	}
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
				$video->{thumbnail} = "//i.ytimg.com/vi/$1/mqdefault.jpg"
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
		if ($text =~ /^[0-9]/) {
			my @seqs = $text =~ /([0-9]+)/g;
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
use LWP::UserAgent;
use URI::Escape qw(uri_escape);
use POSIX;

use constant {
	DOWNLOADING => 'Downloading',
	DONE => 'Done',
	CANCELED => 'Canceled',
	FAILED => 'Failed',
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
		exec('youtube-dl', '--output', $output, '--', $id);
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
	my $url = 'https://www.youtube.com/results?search_query=' . uri_escape($query);
	my $ua = LWP::UserAgent->new;
	my $res = $ua->get($url);
	if ($res->is_success && $res->code == 200) {
		return Geso::YouTube::Parser::parse($res->content);
	}
}

#-------------------------------------------------------------------------------
# HTML

package Geso::HTML;

use CGI qw(escapeHTML);
use File::Find;
use File::Spec::Functions;
use URI::Escape qw(uri_escape);

sub header {
	my $title = shift;
	$title = $title ? escapeHTML($title) . ' - Geso' : 'Geso';
	print <<"EOF";
<!doctype html>
<html>
	<head>
		<title>$title</title>
		<meta charset="utf-8" />
		<meta name="robots" content="noindex, nofollow" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
	</head>
	<body>
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
			print '<li><span>' . escapeHTML($_) . '</span>';
			traverse($root, catdir($base, $_));
			print '</li>';
		} elsif (-f $path) {
			print '<li><a href="/spawn?file='
			    . escapeHTML(uri_escape(catfile($base, $_)))
			    . '">' . escapeHTML($_) . '</a></li>';
		}
	}
	print '</ul>';

}

#-------------------------------------------------------------------------------
# Pages

package Geso::Pages;

use CGI qw(:standard);
use File::Spec::Functions;
use URI::Escape qw(uri_escape);

sub status {
	print header(-type => 'text/html', -charset => 'utf-8');
	Geso::HTML::header('Status');
	print "<h2>Status: $Geso::Player::state{status}</h2>";
	print escapeHTML($Geso::Player::state{file}) . "<br />" if $Geso::Player::state{file};
	print "PID " . $Geso::Player::state{pid} . "<br />" if $Geso::Player::state{pid};
	print <<"EOF";
	<h2>Actions</h2>
	<ul>
		<li><a href="/playpause">Play / Pause</a></li>
		<li><a href="/stop">Stop</a></li>
		<li><a href="/seek?time=-600">Seek -10m</a></li>
		<li><a href="/seek?time=-30">Seek -30s</a></li>
		<li><a href="/seek?time=30">Seek +30s</a></li>
		<li><a href="/seek?time=600">Seek +10m</a></li>
		<li><a href="/chapter?seek=previous">Previous chapter</a></li>
		<li><a href="/chapter?seek=next">Next chapter</a></li>
	</ul>
	<h2>YouTube</h2>
	<ul>
EOF
	foreach (keys %Geso::YouTube::downloads) {
		my $dl = $Geso::YouTube::downloads{$_};
		print '<li>' . escapeHTML("$_ - $dl->{name} ($dl->{status})");
		print " <a href=\"/youtube/cancel?v=$_\">Cancel</a>" if $dl->{status} eq Geso::YouTube::DOWNLOADING;
		print " <a href=\"/youtube/download?v=$_\">Restart</a>" if $dl->{status} eq Geso::YouTube::CANCELED || $dl->{status} eq Geso::YouTube::FAILED;
		print " <a href=\"/youtube/play?v=$_\">Play</a>" if $dl->{status} eq Geso::YouTube::DONE;
		print " <a href=\"/youtube/clear?v=$_\">Clear</a>" if $dl->{status} ne Geso::YouTube::DOWNLOADING;
		print '</li>';
	}
	print <<"EOF";
	</ul>
	<h2>Menu</h2>
	<ul>
		<li><a href="/library">Library</a></li>
		<li>
			<form action="/youtube/search">
				<input name="q" />
				<input type="submit" value="YouTube search" />
			</form>
		</li>
	</ul>
EOF
	Geso::HTML::footer();
}

sub library {
	print header(-type => 'text/html', -charset => 'utf-8');
	Geso::HTML::header('Library');
	print '<h2>Library</h2>';
	my $root = $ENV{DOCUMENT_ROOT};
	Geso::HTML::traverse($root, '.');
	Geso::HTML::footer();
}

sub playpause {
	Geso::Player::playpause();
	print redirect('/');
}

sub stop {
	Geso::Player::stop();
	print redirect('/');
}

sub spawn {
	# http://www.perlmonks.org/?node=Sanitizing%20user-provided%20path%2Ffilenames
	my $file = param('file');
	my $root = $ENV{DOCUMENT_ROOT};
	my $abs = File::Spec->rel2abs($file, $root);
	if ($abs =~ /^\Q$root/) {
		my $root = $ENV{DOCUMENT_ROOT};
		Geso::Player::spawn(catfile($root, $file));
		print redirect('/');
	} else {
		print header(-type => 'text/plain', -charset => 'utf-8', -status => '403 Forbidden');
		print "403 Forbidden\nShady path.\n";
	}
}

sub seek {
	my $time = param('time');
	if ($time =~ /^[-+]?[0-9]+$/) {
		Geso::Player::seek($time);
		print redirect('/');
	} else {
		print header(-type => 'text/plain', -charset => 'utf-8', -status => '403 Forbidden');
		print "403 Forbidden\nInvalid time $time.\n";
	}
}

sub chapter {
	my $seek = param('seek');
	if ($Geso::Player::state{status} ne Geso::Player::OFF) {
		if ($seek eq 'next') {
			Geso::Player::command('o');
		} elsif ($seek eq 'previous') {
			Geso::Player::command('i');
		} else {
			print header(-type => 'text/plain', -charset => 'utf-8', -status => '403 Forbidden');
			print "403 Forbidden\nInvalid direction.\n";
			return;
		}
	}
	print redirect('/');
}

sub youtube_search {
	my $query = param('q') or return print redirect('/');
	print header(-type => 'text/html', -charset => 'utf-8');
	Geso::HTML::header('YouTube search');
	print '<h2>YouTube results</h2>';
	print '<ul>';
	foreach (Geso::YouTube::search($query)) {
		print '<li>';
		print "<img src=\"$_->{thumbnail}\" />";
		print "<a href=\"/youtube/download?v=$_->{id}&name=" . escapeHTML(uri_escape($_->{title})) . '">';
		print escapeHTML($_->{title}) . '</a> ';
		print escapeHTML("($_->{time}, $_->{views} views) by $_->{user}.");
		print '<br />' . $_->{description};
		print '</li>';
	}
	print '</ul>';
}

sub youtube_cancel {
	my $id = param('v');
	Geso::YouTube::cancel($id) if $id;
	print redirect('/');
}

sub youtube_clear {
	my $id = param('v');
	Geso::YouTube::clear($id) if $id;
	print redirect('/');
}

sub youtube_download {
	my $id = param('v');
	my $name = param('name');
	my $dl = $Geso::YouTube::downloads{$id};
	if (!$name && $dl) {
		$name = $dl->{name};
	}
	Geso::YouTube::download($id, $name) if $id and $name;
	print redirect('/');
}

sub youtube_play {
	my $id = param('v');
	my $file = glob catfile($ENV{DOCUMENT_ROOT}, 'youtube', "*.$id.*");
	Geso::Player::spawn($file) if $file;
	print redirect('/');
}

my %pages = (
	'/' => \&status,
	'/playpause' => \&playpause,
	'/stop' => \&stop,
	'/spawn' => \&spawn,
	'/seek' => \&seek,
	'/chapter' => \&chapter,
	'/library' => \&library,
	'/youtube/search' => \&youtube_search,
	'/youtube/cancel' => \&youtube_cancel,
	'/youtube/clear' => \&youtube_clear,
	'/youtube/download' => \&youtube_download,
	'/youtube/play' => \&youtube_play,
);

sub route {
	my $url = url(-absolute => 1);
	my $page = $pages{$url};
	if ($page) {
		$page->();
	} else {
		print header(-type => 'text/plain', -charset => 'utf-8', -status => '404 Not Found');
		print "404 Not Found\n";
	}
}

#-------------------------------------------------------------------------------
# Main

package main;

use CGI::Fast;

while (new CGI::Fast) {
	Geso::Player::update();
	Geso::YouTube::update();
	Geso::Pages::route();
}
