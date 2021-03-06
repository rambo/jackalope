# Jackalope
Implementation of a PHP client for the Jackrabbit server, an implementation of
the Java Content Repository JCR.

Implements the PHPCR interface (see the phpcr submodule in the lib directory for
more about PHPCR).

[http://liip.to/jackalope](http://liip.to/jackalope)

Visit us at #jackalope on irc.freenode.net

* ebi at liip.ch
* david at liip.ch
* chregu at liip.ch

# Preconditions
* libxml version >= 2.7.0 (due to a bug in libxml [http://bugs.php.net/bug.php?id=36501](http://bugs.php.net/bug.php?id=36501))

# Setup
This is only the frontend. In order to actually do something, you need the
Jackrabbit server. Please download Jackrabbit here: http://jackrabbit.apache.org
You need the jackrabbit-standalone-2.x.jar

Once you have the jar, start it with
    $ java -jar jackrabbit*.jar

When you start it the first time, this will create a folder called "jackrabbit" with some subfolders. In order to get the tests up and running, you need to create a workspace called tests.

    cp -rp jackrabbit/workspaces/default/ jackrabbit/workspaces/tests/

You need to adjust the attribute "name" in jackrabbit/workspaces/tests/workspace.xml from "default" to "tests". After changing the workspace.xml, you'll have to restart jackrabbit.

Clone the jackalope project

    git clone git://github.com/jackalope/jackalope.git

Update submodules

    git submodule init
    git submodule update

Now you are ready to use the library. Have a look at api-tests/bootstrap.php
too see how to instantiate a repository.

# Tests
There is our continuos integration server with coverage reports at:
[http://bamboo.liip.ch/browse/JACK](http://bamboo.liip.ch/browse/JACK)

Run phpunit from the api-tests directory. You should have a lot of failed tests,
but no exception. If you have something like this, it works (yeah, FAILURES are ok):
    FAILURES!
    Tests: 224, Assertions: 99, Failures: 8, Errors: 183, Incomplete: 6, Skipped: 10.

There are two kind of tests. The folder *api-tests* contains the
jackalope-api-tests suite to test against the specification.
This is what you want to look at when using jackalope as a phpCR implementation.
In order to run the tests, make sure you have jackrabbit running and added the
"tests" workspace (see below).

In order to run the tests, go to api-tests and run phpunit without any arguments.
It will read phpunit.xml and run all api test suites.

The folder *tests* contains unit tests for the jackalope implementation.
You should only need those if you want to debug jackalope itselves or implement
new features. Again, make sure you have the test workspace in jackrabbit.

