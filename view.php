
<?php/*
	view.php
	Part of the Open Pastebin project - version 0.1-development
	10/8/2004
	Ville Särkkälä - villeveikko@users.sourceforge.net

	This is basically a database viewer. The ID is given as a query string,
	for example:
	http://domain.com/pastebin/view.php?id=349
	view.php then connects to the database, fetches
	the data, and outputs it.
	
	Released under GNU GENERAL PUBLIC LICENSE
	Version 2, June 1991 -  or later
*/?>

<html>
    <head>
        <title>Open Pastebin</title>
    </head>
    <body>
        <?php
            require ( "database.php" );
            if ( isset ( $_REQUEST['id'] ) ) {
                $id = $_REQUEST ['id'];
                database_connect ();
                $array = database_retrieve ( $id );

                $lines = explode ( "\n", $array ["Text"] );
                for ( $i = 0; $i < count ( $lines ); $i++ ) {
                    $lines[$i] = htmlentities ( $lines[$i] );
                    $lines[$i] = str_replace ( "\t", "    ", $lines[$i] );
                    $lines[$i] = str_replace ( " ", "&nbsp;", $lines[$i] );
                    // Insert syntax highlighting stuff here.
                }
            } else {
                die ( "Invalid arguments!" );
            }
        ?>

        <table border="1" cellpadding="2">
            <tr>
                <td>
                    <?php
                        print ( "ID: " . $array['ID'] );
                    ?>
                </td>
            </tr>
            <tr>
                <td>
                    <?php
                        print ( "Date: " . $array['Date'] ); 
                    ?>
                </td>
            </tr>
            <tr>
                <td>
                    <table border="0" cellpadding="0" cellspacing="0">
                        <?php
                            for ( $i = 0; $i < count ( $lines ); $i++ ) {
                                print ( "<tr>" );
                                print ( "<td align=\"right\">" );
                                print ( "<a name=\"" . ( $i + 1 ) . "\">" . ( $i + 1 ) );
                                print ( "</td>" );
                                print ( "<td>&nbsp;&nbsp;&nbsp;</td>" );
                                print ( "<td nowrap align=\"left\">" );
                                print ( "<tt>" . $lines[$i] . "</tt>" );
                                print ( "</td>" );
                                print ( "</tr>" );
                            }
                        ?>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>
