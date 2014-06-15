<?php require_once('login.php'); ?>

<?php/*
	empty.php
	This file removes (from the specified server) the databases	put there by Open Pastebin.
	SECURITY WARNING: DON'T KEEP THIS WHERE IT CAN BE ACCESSED!
*/?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="content-type" content="text/html; charset=iso-8859-1">
	<title>Pastebin</title>
	<style type="text/css" media="all">@import "main.css";</style>
</head>
<body>
	<div id="Content">
		<?php
		    require ( "config.php" );
		    require ( "database.php" );
		    mysql_connect ( $mysql_server, $mysql_username, $mysql_password );
		    mysql_query ( "DROP DATABASE " . $mysql_dbname );
		    print ( "Done!" );
		    database_connect ();
		    print ("connected") or die ("shit!");
		?>
	</div>
</body> 