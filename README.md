Geso
====

Geso is a web interface to control multimedia players. It lets you browse and
play videos in a specific directory, and also search and play YouTube videos.

It's quick and dirty, so while I hope there are no critical security issues,
there's hardly any error reporting, or robustness whatsoever. If you need
authentication, you'll have to use your webservers' facilities like HTTP Basic
Auth.

Features
--------

- Playback controls.
- Directory listing.
- YouTube support: searching, and streaming using youtube-dl.
- An undocumented but simple REST API.

Dependencies
------------

You need the following:

* perl-cgi,
* perl-cgi-fast,
* perl-fcgi,
* perl-string-shellquote,
* perl-html-parser,
* perl-json,
* perl-uri,
* perl-libwww.

You probably also want those:
* mpv,
* youtube-dl.

For the Raspberry Pi, you're going to need ffmpeg compiled with `--enable-mmal`
and mpv compiled with `--enable-rpi`. The latter is enough for software
decoding, while the former enables accelerated video output.

omxplayer was the default player until the tag `omxplayer` in the Git tree. It
could be supported with newer versions using a custom `.play` command, see
below.

Spawning
--------

Use `spawn-fcgi`, or some fancy webserver.

Nginx
-----

A minimal configuration looks like this:

	location / {
		root /srv/geso/;
		include fastcgi.conf;
		fastcgi_pass unix:/run/geso.sock;
	}
	location /geso.css {
		alias somewhere/geso.css;
	}

The `DOCUMENT_ROOT` tells where the video library is located, for browsing.

YouTube
-------

Searching is straightforward. Clicking on the thumbnail streams the video,
clicking on the title downloads it in the library's `youtube` subdirectory.

Custom player
-------------

If you want a directory to use a different player, you may write a `.play`
executable file in that directory. It should emulate mpv's behavior, which is
as follow.

The program is started with one argument, the path or URL to the file we want
to play.

Once started, it reads line-by-line commands from standard input. The most
useful commands :

* `pause` to toggle pause/play state,
* `quit` to exit the player and the wrapper,
* `seek N` to forward/rewind N seconds.
