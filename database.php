
<?php/*
	database.php
	Part of the Open Pastebin project - version 0.1-development
	10/8/2004
	Ville Särkkälä - villeveikko@users.sourceforge.net
	
	MySQL database functions.
	
	Released under GNU GENERAL PUBLIC LICENSE
	Version 2, June 1991 -  or later
*/?>

<?php
    function database_connect ()
    {
        require ( "config.php" );
        $sql_connection = @mysql_connect ( $mysql_server, $mysql_username, $mysql_password );
        if ( !$sql_connection ) {
            die ( "Could not connect to MySQL server!" );
        }
        if ( !mysql_select_db ( $mysql_dbname ) ) {
            if ( !mysql_query ( "CREATE DATABASE " . $mysql_dbname ) ) {
                die ( "Unable to create database: " . mysql_error () );
            }
            if ( !mysql_select_db ( $mysql_dbname ) ) {
                die ( "Database creation error: " . mysql_error () );
            }
        }
        if ( !mysql_query ( "CREATE TABLE IF NOT EXISTS Entries ( ID TINYBLOB, Date DATETIME, Text BLOB )" ) ) {
            die ( "Unable to create table: " . mysql_error () . "<br>" );
        }
    }

    function database_insert ( $uid, $text )
    {
        $entry = mysql_query ( "INSERT INTO Entries(ID, Date, Text) VALUES ( '$uid', CURRENT_TIMESTAMP(), '$text' )" );
        if ( !$entry ) {
            database_create ();
            $entry = mysql_query ( "INSERT INTO Entries(ID, Date, Text) VALUES ( '$uid', CURRENT_TIMESTAMP(), '$text' )" );
            if ( !$entry ) {
                die ( "Query error: " . mysql_error () );
            }
        }
    }

    function database_retrieve ( $id )
    {
        $entry = mysql_query ( "SELECT * FROM Entries WHERE ID = '" . $id . "'" );
        if ( !$entry ) {
            die ( "Query error: " . mysql_error () );
        }
        $array = mysql_fetch_assoc ( $entry );
        if ( !$array ) {
            die ( "Entry does not exist!" );
        }
        return $array;
    }

    function database_exists ( $id )
    {
        $fetch = mysql_query ( "SELECT * FROM Entries WHERE ID = " . $id );
        if ( !$fetch ) {
            die ( "Unable to check if entry exists!" );
        }
        if ( mysql_num_rows ( $fetch ) ) return TRUE;
        else return FALSE;
    }

    function database_entries ()
    {
        $entries = mysql_query ( "SELECT * FROM Entries" );
        if ( !$entries ) {
            database_create ();
            $entries = mysql_query ( "SELECT * FROM Entries" );
            if ( !$entries ) {
                die ( "Unable to get number of entries: " . mysql_error () );
            }
        }
        return mysql_num_rows ( $entries );
    }


?>
