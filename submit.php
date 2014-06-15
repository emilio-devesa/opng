<?php/*
	submit.php
    This is the script that submits the text to the database.
	It then gives the user a link to the entry.
*/?>

<html>
    <head>
        <title>Open Pastebin</title>
    </head>
    <body>
        <?php
            
            //function from David Walsh to get the data from a URL
            //originally posted @ http://davidwalsh.name/isgd-url-php
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
            require ( "sanitize.php" );

            $text = $_POST ['input_text'];
            $lang = $_POST ['input_language'];
            $topic = $_POST ['input_topic'];
            if ( !isset ( $_POST ['input_text'] ) ) die ( "Input text is not set!" );
            if ( !isset ( $_POST ['input_language'] ) ) die ( "Input language is not set!" );
            if ( !isset ( $_POST ['input_topic'] ) ) die ( "Input topic is not set!" );

            database_connect ();
            $id = crypt ($text); //now the id is a hash of $text instead of a sequential number: 

            database_insert ( $id, sanitize($lang), $$text, sanitize($topic));
            print ( "Entry added.<br>" );
            $url  = "http://" . $_SERVER['HTTP_HOST'] . dirname ( $_SERVER['PHP_SELF'] );
            $url .= "view.php?id=" . $id;
            print ( "Link:<br><a href=\"" . $url . "\">" . $url . "</a>" );
            
            //print the short URL
            print ("<br>");
            $short_url = short_url ($url);
            print ("Short link:<br><a href=\"" . $short_url . "\">" . $short_url . "</a>" );
        ?>
        <p>Return to <a href="index.php">index</a></p>
    </body>
</html>