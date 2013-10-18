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

Other questions
---------------

**What is this about "API rate limited"?**  
Twitter limits how much you can use their API so that their servers don't get
bogged down. It should fix itself after a few minutes.

**Why does that number at the top of the page keep changing?**  
That is the number of favorites that have been downloaded so far, and they are
gradually downloaded in batches of 200 (the maximum allowed).

**Can I try out a demo somewhere?**
Not right now. The current code base does not handle favorites of private
accounts in a way that doesn't expose those users' content. Please take that
into consideration when you set up your own installation. Until I add some code
that does a better job accounting for private accounts, you may inadvertantly
expose people you're following.
