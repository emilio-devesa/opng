<?php
require("../config.php"); // Cargar configuración

// Contraseña encriptada (usa password_hash() en lugar de texto plano)
$PasswordHash = password_hash('demo', PASSWORD_BCRYPT);

// Si el usuario ya está autenticado, redirigir a la página de administración
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (basename($_SERVER['PHP_SELF']) === 'login.php') {
        header('Location: admin.php');
        exit();
    }
    return; // Evita ejecutar más código en páginas autenticadas
}

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
    $csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración - Open Pastebin</title>
    <link rel="stylesheet" href="../main.css">
</head>
<body>
   <div id="Content">
         <div class="caption"><?php echo htmlspecialchars($error); ?></div>
         <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" name="pwd">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <label for="passwd">Password:</label>
            <input class="text" id="passwd" name="passwd" type="password" required/>
            <br/>
            <!-- Token CSRF -->
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"/>
            <br><br>
            <input class="text" type="submit" name="submit_pwd" value="Login"/>
         </form>
   </div>
</body>
</html>
<?php
}
?>