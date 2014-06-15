<?php/*
	pastebin.php
	This is the main page. It allows the user to enter the text, and then goes to submit.php
*/?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="content-type" content="text/html; charset=iso-8859-1">
	<title>Open Pastebin</title>
	<style type="text/css" media="all">@import "main.css";</style>
</head>
<body>
	<div id="Content">
        <?php
            require ( "highlight.php" );
            require ( "xmlparser.php" );
            $xml_parser = new CXmlParser ();
            $document = $xml_parser->parse ( "rules.xml" );
        ?>
        <form method="post" action="submit.php">
            Topic:<input type="text" name="input_topic"><br />
            Select language:<br>
            <select name="input_language">
            <?php
                var_dump ( $document );
                for ( $i = 0; $i < count ( $document ['RULE'] ); $i++ ) {
                    print ( "<option value=\"" . $i . "\">" );
                    print ( $document ['RULE'][$i]['attributes']['NAME'] );
                    print ( "</option>" );
                }
				//show the popular ones
				foreach ($CONF['all_syntax'] as $code=>$name)
				{
					if (in_array($code, $CONF['popular_syntax']))
					{
						$sel=($code==$page['current_format'])?"selected=\"selected\"":"";
						echo "<option $sel value=\"$code\">$name</option>";
					}
				}

				echo "<option value=\"text\">----------------------------</option>";

				//show all formats
				foreach ($CONF['all_syntax'] as $code=>$name)
				{
					$sel=($code==$page['current_format'])?"selected=\"selected\"":"";
					if (in_array($code, $CONF['popular_syntax']))
						$sel="";
					echo "<option $sel value=\"$code\">$name</option>";
				
				}
			?>
            </select><br>
            Enter text here:<br>
            <textarea name="input_text" rows="25" cols="80"></textarea>
            <br><br>
            <input type="submit" value="Submit">
        </form><br /><br />
        <p>Return to the <a href="index.php">index</a></p><br />
	</div>
</body>
</html>