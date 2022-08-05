
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
  Modified by Emilio Devesa - emilio.devesa@udc.es
  Added a Short URL link feature, using David Walsh code
  and is.gd short-url service.
  
  09/30/2010
  Modified by Emilio Devesa - emilio.devesa@udc.es
  Changed to improve privacy by hashing the resulting URLs
  
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
            
            
            require ( "database.php" );
            require ( "highlight.php" );
            if ( !isset ( $_POST ['input_text'] ) ) die ( "Input text is not set!" );
            if ( !isset ( $_POST ['input_language'] ) ) die ( "Input language is not set!" );
            $text = $_POST ['input_text'];

            database_connect ();
            $id = crypt ($text); //line modified by Emilio Devesa
                                 //now id is not a sequential number: is a hash of $text

            database_insert ( $id, $_POST['input_language'], $text );
            print ( "Entry added.<br>" );
            $url  = "http://" . $_SERVER['HTTP_HOST'] . dirname ( $_SERVER['PHP_SELF'] );
            $url .= "view.php?id=" . $id; //line modified by Emilio Devesa
                                          //no needed slash before "view.php..."
            print ( "Link:<br><a href=\"" . $url . "\">" . $url . "</a>" );
            
            //Now give the short URL
            //made by Emilio Devesa using David Walsh function
            print ("<br>");
            $short_url = short_url ($url);
            print ("Short link:<br><a href=\"" . $short_url . "\">" . $short_url . "</a>" );
        ?>
    </body>
</html>
