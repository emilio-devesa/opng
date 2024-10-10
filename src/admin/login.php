<?php
session_start();

// Contraseña encriptada (usa password_hash() en lugar de texto plano)
$PasswordHash = password_hash('demo', PASSWORD_BCRYPT);

// Si el usuario ya está autenticado, redirigir a la página de administración
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: admin.php');
    exit();
}

// Verificar si el formulario fue enviado
if (isset($_POST['submit_pwd'])) {
    $pass = isset($_POST['passwd']) ? $_POST['passwd'] : '';

    // Verificar la contraseña ingresada contra el hash
    if (password_verify($pass, $PasswordHash)) {
        // Contraseña correcta, guardar sesión
        $_SESSION['logged_in'] = true;
        header('Location: admin.php'); // Redirigir al admin
        exit();
    } else {
        showForm("Wrong password");
        exit();
    }
} else {
    showForm();
    exit();
}

// Mostrar el formulario de inicio de sesión
function showForm($error="LOGIN") {
    // Generar un token CSRF para el formulario
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
   <meta http-equiv="content-type" content="text/html; charset=iso-8859-1">
   <title>Pastebin Login</title>
   <style type="text/css" media="all">@import "../main.css";</style>
</head>
<body>
   <div id="Content">
         <div class="caption"><?php echo htmlentities($error); ?></div>
         <form action="<?php echo htmlentities($_SERVER['PHP_SELF']); ?>" method="post" name="pwd">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <label for="passwd">Password:</label>
            <input class="text" id="passwd" name="passwd" type="password" required/>
            <br/>
            <input class="text" type="submit" name="submit_pwd" value="Login"/>
         </form>
   </div>
</body>
</html>
<?php
}
?>
