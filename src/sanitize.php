
<?php/*
	sanitize.php
	This script sanitizes user input for XSS attacks
*/?>


<?
function sanitize($input) 
{
	//Never EVER trust user input
	$search = array(
			'@<\s*script[^>]*?>.*?<\s*/\s*script\s*>@si', // Strip out javascript
			'@<\s*[\/\!]*?[^<>]*?>@si', // Strip out HTML tags
			'@<\s*style[^>]*?>.*?<\s*/\s*style\s*>@siU', // Strip style tags properly
			'@<![\s\S]*?–[ \t\n\r]*>@' // Strip multi-line comments
			);
	$output = preg_replace($search, ”, $input);
	return $output;
}
?>