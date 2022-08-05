
<?php/*
	submit.php
	Part of the Open Pastebin project - version 0.2-development
	10/8/2004
	Ville Särkkälä - villeveikko@users.sourceforge.net

	This is the script that submits the text to the database.
	It then gives the user a link to the entry.

	Released under GNU GENERAL PUBLIC LICENSE
	Version 2, June 1991 -  or later

  --
  
  09/31/2010
  Modified by Emilio Devesa - edevmon@gmail.com
  Added a Short URL link feature, using David Walsh code
  and is.gd short-url service.
  
  09/30/2010
  Modified by Emilio Devesa - edevmon@gmail.com
  Changed to improve privacy by hashing the resulting URLs

  05/10/2016
  Modified by Emilio Devesa - edevmon@gmail.com
  Improvements by Jarett Stevens applied (jarett.stevens@gmail.com)
  Now jumping to v0.4
  
*/?>

<html>
    <head>
        <title>Open Pastebin NG</title>
    </head>
    <body>
        <?php
            
            //function from David Walsh
            //originally posted @ http://davidwalsh.name/isgd-url-php
            //gets the data from a URL
            function short_url($url){
                //get content
                $ch = curl_init();
                $timeout = 5;

                curl_setopt($ch,CURLOPT_URL,'http://is.gd/api.php?longurl='.$url);
                curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
                curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);

                $content = curl_exec($ch);
                curl_close($ch);

                //return the data
                return $content;
            }
            
            require ( "config.php" );
            require ( "database.php" );
            require ( "highlight.php" );
            if ( !isset ( $_POST ['input_text'] ) ) die ( "Input text is not set!" );
            if ( !isset ( $_POST ['input_language'] ) ) die ( "Input language is not set!" );
            $text = $_POST ['input_text'];

            database_connect ();
            $id = md5 ($text); // Using md5() instead of crypt() to avoid some characters in resulting string

            // Using mysql_real_escape_string() instead of plain text to avoid mysql injections
            database_insert ( $id, $_POST['input_language'], mysql_real_escape_string($text) );
            print ( "Entry added.<br>" );
            $url  = "http://" . $_SERVER['HTTP_HOST'] . dirname ( $_SERVER['PHP_SELF'] );
            $url .= "/view.php?id=" . $id;
            print ( "Link:<br><a href=\"" . $url . "\">" . $url . "</a>" );
            
            // Option suggested by Jarett Stevens
            if ($short_url_enable == "yes") {
                // Now give the short URL using David Walsh function
                print ("<br>");
                $short_url = short_url ($url);
                print ("Short link:<br><a href=\"" . $short_url . "\">" . $short_url . "</a>" );
            }
        ?>
    </body>
</html>
