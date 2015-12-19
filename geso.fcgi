#!/usr/bin/env perl

use strict;
use warnings;
use utf8;

use CGI qw(:standard);
use CGI::Fast;
use File::Find;
use File::Spec::Functions;
use IO::Handle;
use String::ShellQuote qw(shell_quote);
use URI::Escape qw(uri_escape);
use POSIX;

#-------------------------------------------------------------------------------
# Actions

use constant {
	OFF => 'Off',
	PLAYING => 'Playing',
	PAUSED => 'Paused',
};

my %player = (
	status => OFF
);

sub command_player {
	return if $player{status} eq OFF;
	my $fh = $player{in};
	print $fh shift;
	$fh->flush();
}

sub reset_player {
	close $player{in};
	$player{status} = OFF;
	delete $player{pid};
}

sub spawn_player {
	kill_player() if $player{status} ne OFF;
	my $arg = shift;
	$player{file} = $arg;
	if ($arg =~ /^-/) { $arg = "./$arg"; }
	$player{pid} = open($player{in}, '|-', shell_quote('omxplayer', $arg));
	$player{status} = PLAYING;
}

sub update_player {
	return if $player{status} eq OFF;
	my $kid = waitpid($player{pid}, WNOHANG);
	reset_player() if $kid > 0;
}

sub kill_player {
	return if $player{status} eq OFF;
	command_player('q');
	my $kid = waitpid($player{pid}, 0);
	reset_player() if $kid > 0;
}

sub playpause_player {
	return if $player{status} eq OFF;
	command_player(' ');
	$player{status} = $player{status} eq PLAYING ? PAUSED : PLAYING;
}

sub seek_player {
	return if $player{status} eq OFF;
	use integer;
	my $time = shift;
	my $abstime = abs($time);
	my $big_steps = $abstime / 600;
	my $small_steps = ($abstime % 600) / 30;
	if ($time >= 30) {
		command_player(("\027[A" x $big_steps) . ("\027[C" x $small_steps));
	} elsif ($time <= -30) {
		command_player(("\027[B" x $big_steps) . ("\027[D" x $small_steps));
	}
}

#-------------------------------------------------------------------------------
# Pages

sub html_header {
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

sub html_footer {
	print<<EOF;
	</body>
</html>
EOF
}

sub traverse {
	print '<ul>';
	my ($root, $base) = @_;
	opendir(my $dh, catdir($root, $base));
	foreach (sort readdir($dh)) {
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
	closedir($dh);
	print '</ul>';

}

sub status_page {
	print header(-type => 'text/html', -charset => 'utf-8');
	html_header('Status');
	print "<h2>Status: $player{status}</h2>";
	print escapeHTML($player{file}) . "<br />" if $player{file};
	print "PID " . $player{pid} . "<br />" if $player{pid};
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
	<h2>Menu</h2>
	<ul>
		<li><a href="/library">Library</a></li>
	</ul>
EOF
	html_footer();
}

sub library_page {
	print header(-type => 'text/html', -charset => 'utf-8');
	html_header('Library');
	print '<h2>Library</h2>';
	my $root = $ENV{DOCUMENT_ROOT};
	traverse($root, '.');
	html_footer();
}

sub playpause_page {
	playpause_player();
	print redirect('/');
}

sub stop_page {
	kill_player();
	print redirect('/');
}

sub spawn_page {
	# http://www.perlmonks.org/?node=Sanitizing%20user-provided%20path%2Ffilenames
	my $file = param('file');
	my $root = $ENV{DOCUMENT_ROOT};
	my $abs = File::Spec->rel2abs($file, $root);
	if ($abs =~ /^\Q$root/) {
		my $root = $ENV{DOCUMENT_ROOT};
		spawn_player(catfile($root, $file));
		print redirect('/');
	} else {
		print header(-type => 'text/plain', -charset => 'utf-8', -status => '403 Forbidden');
		print "403 Forbidden\nShady path.\n";
	}
}

sub seek_page {
	my $time = param('time');
	if ($time =~ /^[-+]?[0-9]+$/) {
		seek_player($time);
		print redirect('/');
	} else {
		print header(-type => 'text/plain', -charset => 'utf-8', -status => '403 Forbidden');
		print "403 Forbidden\nInvalid time $time.\n";
	}
}

sub chapter_page {
	my $seek = param('seek');
	if ($player{status} ne OFF) {
		if ($seek eq 'next') {
			command_player('o');
		} elsif ($seek eq 'previous') {
			command_player('i');
		} else {
			print header(-type => 'text/plain', -charset => 'utf-8', -status => '403 Forbidden');
			print "403 Forbidden\nInvalid direction.\n";
			return;
		}
	}
	print redirect('/');
}

my %pages = (
	'/' => \&status_page,
	'/playpause' => \&playpause_page,
	'/stop' => \&stop_page,
	'/spawn' => \&spawn_page,
	'/seek' => \&seek_page,
	'/chapter' => \&chapter_page,
	'/library' => \&library_page,
);

while (new CGI::Fast) {
	update_player();
	my $url = url(-absolute => 1);
	my $page = $pages{$url};
	if ($page) {
		$page->();
	} else {
		print header(-type => 'text/plain', -charset => 'utf-8', -status => '404 Not Found');
		print "404 Not Found\n";
	}
}
