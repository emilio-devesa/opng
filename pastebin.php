<?php/*
	pastebin.php
    OPNG v0.3-development

	Part of the Open Pastebin project - version 0.2-development
	10/8/2004
	Ville S�rkk�l� - villeveikko@users.sourceforge.net

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
        <?php
            require ( "highlight.php" );
            require ( "xmlparser.php" );
            $xml_parser = new CXmlParser ();
            $document = $xml_parser->parse ( "rules.xml" );
        ?>
        <form method="post" action="submit.php">
            Select language:<br>
            <select name="input_language">
            <?php
                var_dump ( $document );
                for ( $i = 0; $i < count ( $document ['RULE'] ); $i++ ) {
                    print ( "<option value=\"" . $i . "\">" );
                    print ( $document ['RULE'][$i]['attributes']['NAME'] );
                    print ( "</option>" );
                }
            ?>
            </select><br>
            Enter text here:<br>
            <textarea name="input_text" rows="25" cols="80"></textarea>
            <br><br>
            <input type="submit" value="Submit">
        </form>
        <a href="https://github.com/emiliodevesadrums/opng">opng</a>
    </body>
</html>
