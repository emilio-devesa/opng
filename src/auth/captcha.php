<?php
// auth/captcha.php
// Local CAPTCHA generated with GD (S7).
//
// Improvements over the previous version (plain text on a gray background):
//   - 160x60 true-color image.
//   - Background noise (dots and lines) and noise over the text.
//   - Each character gets its own color, size, offset and rotation.
//   - Gentle horizontal row distortion.
//   - no-store headers: the browser never reuses an old code.
//
// Requires no external services or keys. When a TTF font is available
// (DejaVu on Linux, Arial on macOS) it is used to rotate the characters;
// otherwise the scaled GD bitmap font is used instead.

require(__DIR__ . '/../includes/session.php');
session_start_secured();
require_once(__DIR__ . '/../includes/captcha.php');
require_once(__DIR__ . '/../includes/ratelimit.php');
require_once(__DIR__ . '/../database.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

/**
 * Return to the page that requested the mode switch. Only local absolute
 * paths are accepted, so the endpoint cannot be used as an open redirect.
 */
function captcha_redirect_target(): string
{
    $return = $_GET['return'] ?? null;
    if (
        is_string($return)
        && $return !== ''
        && $return[0] === '/'
        && !str_starts_with($return, '//')
        && preg_match('/[\x00-\x1F\x7F\\\\]/', $return) !== 1
    ) {
        return $return;
    }

    return '../index.php';
}

// Explicit accessible mode. The image stays the default challenge; the text
// challenge is only activated by this opt-in and is rate-limited separately.
$mode = $_GET['mode'] ?? null;
if ($mode === 'text' || $mode === 'image') {
    if ($mode === 'text') {
        $db = database_connect();
        if (!$db) {
            http_response_code(503);
            header('Retry-After: 30');
            exit;
        }
        $limit = rate_limit_hit($db, 'captcha_text', client_ip(), 30, 300);
        $db->close();
        if ($limit === RateLimitResult::Unavailable) {
            http_response_code(503);
            header('Retry-After: 30');
            exit;
        }
        if ($limit === RateLimitResult::Limited) {
            http_response_code(429);
            header('Retry-After: 300');
            exit;
        }

        captcha_enable_text_mode();
        if (($_GET['refresh'] ?? null) === '1') {
            captcha_clear();
        }
        captcha_text_challenge();
    } else {
        captcha_disable_text_mode();
    }

    header('Location: ' . captcha_redirect_target(), true, 303);
    exit;
}

// Generating an image costs CPU and memory, so cap requests per client IP.
$db = database_connect();
if (!$db) {
    http_response_code(503);
    header('Retry-After: 30');
    exit;
}
$limit = rate_limit_hit($db, 'captcha', client_ip(), 120, 300);
$db->close();
if ($limit === RateLimitResult::Unavailable) {
    http_response_code(503);
    header('Retry-After: 30');
    exit;
}
if ($limit === RateLimitResult::Limited) {
    http_response_code(429);
    header('Retry-After: 300');
    exit;
}

// --- Random code ------------------------------------------------------------
// No ambiguous characters (I, O, 0, 1) to reduce human errors. Reuse the
// current challenge unless the user asked for a refresh, so the attempt budget
// applies to the same code across a failed submission and the form reload.
$refresh = ($_GET['refresh'] ?? null) === '1';
$existing_code = $_SESSION['captcha'] ?? null;
if (!$refresh && is_string($existing_code) && captcha_image_challenge_available()) {
    $code = $existing_code;
} else {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 5; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    captcha_issue_image($code);
}

// --- Headers ----------------------------------------------------------------
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

$width = 160;
$height = 60;
$image = imagecreatetruecolor($width, $height);

// --- Background -------------------------------------------------------------
$bg = imagecolorallocate(
    $image,
    random_int(238, 252),
    random_int(238, 252),
    random_int(238, 252)
);
imagefilledrectangle($image, 0, 0, $width, $height, $bg);

// --- Background noise: dots and lines --------------------------------------
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
 * First readable TTF font found, or null when none is available.
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

// --- Characters -------------------------------------------------------------
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
            random_int(24, 29),          // size
            random_int(-20, 20),          // rotation
            $x,
            random_int(38, 50),           // baseline
            $color,
            $font,
            $char
        );
        $x += random_int(26, 30);
    } else {
        // TTF fallback: draw the character on a transparent tile and scale it
        // 2x onto the main image.
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

// --- Noise over the text ----------------------------------------------------
for ($i = 0; $i < 40; $i++) {
    $noise = imagecolorallocate(
        $image,
        random_int(80, 180),
        random_int(80, 180),
        random_int(80, 180)
    );
    imagefilledellipse($image, random_int(0, $width), random_int(0, $height), 2, 2, $noise);
}

// --- Gentle horizontal distortion -------------------------------------------
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

// --- Output -----------------------------------------------------------------
imagepng($image);
imagedestroy($image);
