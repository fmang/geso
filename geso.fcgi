#!/usr/bin/env perl

use strict;
use warnings;
use utf8;

use CGI qw(:standard);
use CGI::Fast;

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
	print "Hello from Geso.";
	html_footer();
}

while (new CGI::Fast) {
	my $url = url(-absolute => 1);
	if ($url eq '/') {
		status_page();
	} else {
		print header(-type => 'text/plain', -charset => 'utf-8', -status => '404 Not Found');
		print "404 Not Found\n";
	}
}
