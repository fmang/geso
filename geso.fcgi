#!/usr/bin/env perl

use strict;
use warnings;
use utf8;

use CGI qw(:standard);
use CGI::Fast;
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
	$player{status} eq PLAYING ? PAUSED : PLAYING;
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

sub status_page {
	print header(-type => 'text/html', -charset => 'utf-8');
	html_header();
	print "<h2>Status: $player{status}</h2>";
	print escapeHTML($player{file}) . "<br />" if $player{file};
	print "PID " . $player{pid} . "<br />" if $player{pid};
	print <<"EOF";
	<h2>Actions</h2>
	<ul>
		<li><a href="/playpause">Play / Pause</a></li>
		<li><a href="/stop">Stop</a></li>
	</ul>
	<h2>Files</h2>
	<ul>
EOF
	my $root = $ENV{DOCUMENT_ROOT};
	opendir(my $dh, $root);
	while (my $file = readdir($dh)) {
		next if $file =~ /^\./;
		my $path = catfile($root, $file);
		next unless -f $path;
		print '<li><a href="/spawn?file=' . escapeHTML(uri_escape($file)) . '">' . escapeHTML($file) . '</a></li>';
	}
	closedir($dh);
	print "</ul>";
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
	my $file = param('file');
	if ($file =~ /^[^\/\\]*$/) {
		my $root = $ENV{DOCUMENT_ROOT};
		spawn_player(catfile($root, $file));
		print redirect('/');
	} else {
		print header(-type => 'text/plain', -charset => 'utf-8', -status => '403 Forbidden');
		print "403 Forbidden\nInvalid file.\n";
	}
}

my %pages = (
	'/' => \&status_page,
	'/playpause' => \&playpause_page,
	'/stop' => \&stop_page,
	'/spawn' => \&spawn_page,
);

while (new CGI::Fast) {
	my $url = url(-absolute => 1);
	my $page = $pages{$url};
	if ($page) {
		$page->();
	} else {
		print header(-type => 'text/plain', -charset => 'utf-8', -status => '404 Not Found');
		print "404 Not Found\n";
	}
}
