<?php
session_start();

    // Crear un código aleatorio
    $captcha_code = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ23456789"), 0, 5);
    $_SESSION['captcha'] = $captcha_code;

    // Crear imagen
    header("Content-Type: image/png");
    $image = imagecreate(85, 40);
    $background = imagecolorallocate($image, 200, 200, 200);
    $text_color = imagecolorallocate($image, 0, 0, 0);
    imagestring($image, 5, 20, 10, $captcha_code, $text_color);
    imagepng($image);
    imagedestroy($image);

?>