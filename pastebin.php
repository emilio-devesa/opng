
<?php/*
	pastebin.php
	Part of the Open Pastebin project - version 0.1-development
	10/8/2004
	Ville Särkkälä - villeveikko@users.sourceforge.net

	This is the main/index page. It allows the user to enter
	the text, and then goes to submit.php.

	Released under GNU GENERAL PUBLIC LICENSE
	Version 2, June 1991 -  or later
*/?>

<html>
    <head>
        <title>Open Pastebin</title>
    </head>
    <body>
        Enter text here:
        <br>
        <form method="post" action="submit.php">
            <textarea name="input_text" rows="25" cols="80"></textarea>
            <br><br>
            <input type="submit" value="Submit">
        </form>
        <a href="http://www.sourceforge.net/projects/openpastebin/">Open Pastebin</a>
    </body>
</html>
