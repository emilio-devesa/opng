<?php require_once('login.php'); ?>

<?php/*
	drop.php
	Feed it the ID of a post you wish to remove. 
	SECURITY WARNING: DON'T KEEP THIS WHERE IT CAN BE ACCESSED!
*/?>

<?php 
	require ( "config.php" );
	$sql_connection = @mysql_connect ( $mysql_server, $mysql_username, $mysql_password );
	if ( !$sql_connection ) {
	    die ( "Could not connect to MySQL server!" );
	}
	$tbl_name="Entries"; // Table name
	mysql_select_db("$mysql_dbname")or die("cannot select DB");

	?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="content-type" content="text/html; charset=iso-8859-1">
	<title>Pastebin</title>
	<style type="text/css" media="all">@import "main.css";</style>
</head>
<body>
	<div id="Content">
		<form method="post" action="drop_id.php">
			ID to remove:<input type="text" name="input_ID"><br /><br><br>
			<input type="submit" value="Submit">
		</form><br /><br />
		<p>Return to the <a href="index.php">index</a></p><br />
	</div>
</body> 