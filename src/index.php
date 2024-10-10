<?php
// index.php
// Index Page

require("config.php");

// Crear una conexión usando mysqli
$mysqli = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);

// Verificar la conexión
if ($mysqli->connect_error) {
    die("Conexión fallida: " . $mysqli->connect_error);
}

// Definir el nombre de la tabla
$tbl_name = "Entries";

// Consulta SQL
$sql = "SELECT * FROM $tbl_name ORDER BY ID DESC";
$result = $mysqli->query($sql);

// Verificar si hay resultados
if (!$result) {
    die("Error en la consulta: " . $mysqli->error);
}

// Cerrar la conexión al final del script
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="content-type" content="text/html; charset=iso-8859-1">
    <title>Open Pastebin</title>
    <style type="text/css" media="all">@import "main.css";</style>
</head>
<body>
    <div id="Content">
        <!-- HTML table -->
        <table width="50%" border="0" align="center" cellpadding="3" cellspacing="1" bgcolor="#CCCCCC">
            <tr>
                <td colspan="5" align="right" bgcolor="#E6E6E6"><a href="pastebin.php"><strong>Create New Topic</strong></a></td>
            </tr>
            <tr>
                <td width="6%" align="center" bgcolor="#E6E6E6"><strong><font color="#000">ID#</font></strong></td>
                <td width="53%" align="center" bgcolor="#E6E6E6"><strong><font color="#000">Topic</font></strong></td>
                <td width="13%" align="center" bgcolor="#E6E6E6"><strong><font color="#000">Date</font></strong></td>
            </tr>
            <?php
            // Bucle para mostrar resultados de la consulta
            while ($rows = $result->fetch_assoc()) { ?>
            <tr>
                <td bgcolor="#E6E6E6"><?php echo $rows['ID']; ?></td>
                <td bgcolor="#E6E6E6"><a href="view.php?id=<?php echo $rows['ID']; ?>"><?php echo $rows['Topic']; ?></a></td>
                <td align="center" bgcolor="#E6E6E6"><?php echo $rows['Date']; ?></td>
            </tr>
            <?php } ?>
            <tr>
                <td colspan="5" align="right" bgcolor="#E6E6E6"><a href="pastebin.php"><strong>Create New Topic</strong> </a></td>
            </tr>
        </table>
    </div>

    <?php
    // Cerrar la conexión
    $mysqli->close();
    ?>
</body>
</html>