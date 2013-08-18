Starmonger
==========

A simple Twitter favorites archiver

What is this?
-------------
Starmonger is a simple website for downloading and archiving your favorites from
Twitter. It's kind of similar to what [Stellar](http://stellar.io/) does, except
it's just your favorites, and you get a searchable database that you can backup
on your own disk.

Why is this?
------------
Everyone keeps writing stuff on Twitter that I want read later, or file away for
when I might need it later, or is just plain great. This is a simple tool for
helping you do that on your own server. I don't want to maintain this thing for
you in perpetuity, so that's why it's code you download instead of a hosted
service.

What do I need to run it?
-------------------------
The technologies used here are PHP and SQLite3 and a web server to make it run.
If you're not sure about those things, you may need to find a tech-savvy friend.

How to install
--------------
* Unzip this onto your web server
* Register a new [application](https://dev.twitter.com/apps) on Twitter
* Rename `config-example.php` to `config.php` and edit with your Twitter OAuth
  credentials
* Load it up in a browser!

Try it out
----------
You can try out Starmonger at http://phiffer.org/starmonger/
