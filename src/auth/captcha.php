<?php
// auth/captcha.php
// CAPTCHA local generado con GD (fix S7).
//
// Mejoras frente a la versión anterior (texto plano sobre fondo gris):
//   - Imagen 160x60 en color real.
//   - Ruido de fondo (puntos y líneas) y ruido delante del texto.
//   - Cada carácter usa color, tamaño, desplazamiento y rotación propios.
//   - Distorsión horizontal suave por filas.
//   - Cabeceras no-store: el navegador nunca reutiliza un código viejo.
//
// No requiere servicios externos ni claves. Si hay una fuente TTF disponible
// (DejaVu en Linux, Arial en macOS) se usa para rotar los caracteres; si no,
// se recurre a la fuente bitmap de GD ampliada.

require(__DIR__ . '/../includes/session.php');
session_start_secured();

// --- Código aleatorio -------------------------------------------------------
// Sin caracteres ambiguos (I, O, 0, 1) para reducir errores humanos.
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$code = '';
for ($i = 0; $i < 5; $i++) {
    $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}
$_SESSION['captcha'] = $code;

// --- Cabeceras --------------------------------------------------------------
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

$width = 160;
$height = 60;
$image = imagecreatetruecolor($width, $height);

// --- Fondo ------------------------------------------------------------------
$bg = imagecolorallocate(
    $image,
    random_int(238, 252),
    random_int(238, 252),
    random_int(238, 252)
);
imagefilledrectangle($image, 0, 0, $width, $height, $bg);

// --- Ruido de fondo: puntos y líneas ---------------------------------------
for ($i = 0; $i < 120; $i++) {
    $noise = imagecolorallocate(
        $image,
        random_int(150, 220),
        random_int(150, 220),
        random_int(150, 220)
    );
    imagefilledellipse(
        $image,
        random_int(0, $width),
        random_int(0, $height),
        random_int(1, 3),
        random_int(1, 3),
        $noise
    );
}

for ($i = 0; $i < 6; $i++) {
    $noise = imagecolorallocate(
        $image,
        random_int(120, 200),
        random_int(120, 200),
        random_int(120, 200)
    );
    imageline(
        $image,
        random_int(0, $width),
        random_int(0, $height),
        random_int(0, $width),
        random_int(0, $height),
        $noise
    );
}

/**
 * Primera fuente TTF legible encontrada, o null si no hay ninguna.
 */
function captcha_font(): ?string
{
    $candidates = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        '/usr/local/share/fonts/DejaVuSans-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
        '/System/Library/Fonts/Supplemental/Verdana Bold.ttf',
    ];

    foreach ($candidates as $font) {
        if (is_readable($font)) {
            return $font;
        }
    }

    return null;
}

// --- Caracteres -------------------------------------------------------------
$font = captcha_font();
$x = 10;

for ($i = 0; $i < strlen($code); $i++) {
    $char = $code[$i];

    if ($font !== null) {
        $color = imagecolorallocate(
            $image,
            random_int(0, 90),
            random_int(0, 90),
            random_int(0, 90)
        );
        imagettftext(
            $image,
            random_int(24, 29),          // tamaño
            random_int(-20, 20),          // rotación
            $x,
            random_int(38, 50),           // línea base
            $color,
            $font,
            $char
        );
        $x += random_int(26, 30);
    } else {
        // Fallback sin TTF: dibuja el carácter en una ficha transparente y la
        // amplía 2x sobre la imagen principal.
        $tile = imagecreate(12, 22);
        $transparent = imagecolorallocate($tile, 255, 0, 255);
        imagecolortransparent($tile, $transparent);
        imagefill($tile, 0, 0, $transparent);
        $tile_color = imagecolorallocate(
            $tile,
            random_int(0, 90),
            random_int(0, 90),
            random_int(0, 90)
        );
        imagestring($tile, 5, 1, 2, $char, $tile_color);
        imagecopyresized(
            $image,
            $tile,
            $x,
            random_int(16, 24),
            0,
            0,
            18,
            32,
            9,
            16
        );
        imagedestroy($tile);
        $x += random_int(26, 30);
    }
}

// --- Ruido delante del texto ------------------------------------------------
for ($i = 0; $i < 40; $i++) {
    $noise = imagecolorallocate(
        $image,
        random_int(80, 180),
        random_int(80, 180),
        random_int(80, 180)
    );
    imagefilledellipse($image, random_int(0, $width), random_int(0, $height), 2, 2, $noise);
}

// --- Distorsión horizontal suave -------------------------------------------
$source = imagecreatetruecolor($width, $height);
imagecopy($source, $image, 0, 0, 0, 0, $width, $height);
for ($y = 0; $y < $height; $y++) {
    $offset = (int) round(2 * sin($y / 7));
    if ($offset >= 0) {
        imagecopy($image, $source, $offset, $y, 0, $y, $width - $offset, 1);
    } else {
        imagecopy($image, $source, 0, $y, -$offset, $y, $width + $offset, 1);
    }
}
imagedestroy($source);

// --- Salida -----------------------------------------------------------------
imagepng($image);
imagedestroy($image);
