<?php

/**
 * Custom error documents (ROB-13).
 *
 * 404/500 must render the shared localized layout, set the right status and
 * never leak diagnostics. Apache and the container Nginx must route to them.
 */

$root = dirname(__DIR__);
$failures = [];

function expect_error_page(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

foreach ([
    'src/404.php' => ['http_response_code(404);', 'not_found_title', 'not_found_message'],
    'src/500.php' => ['http_response_code(500);', 'server_error_title', 'server_error_message'],
] as $path => $needles) {
    $source = file_get_contents($root . '/' . $path);
    expect_error_page(is_string($source), "{$path} exists", $failures);
    if (!is_string($source)) {
        continue;
    }

    foreach ($needles as $needle) {
        expect_error_page(str_contains($source, $needle), "{$path} contains {$needle}", $failures);
    }
    expect_error_page(
        str_contains($source, 'layout_header.php') && str_contains($source, 'layout_footer.php'),
        "{$path} uses the shared layout",
        $failures
    );
    expect_error_page(
        !str_contains($source, 'getMessage()') && !str_contains($source, 'getTrace'),
        "{$path} does not expose exception details",
        $failures
    );
}

$catalogs = glob($root . '/src/assets/lang/*.json') ?: [];
expect_error_page(count($catalogs) === 6, 'all six language catalogs are present', $failures);
foreach ($catalogs as $catalog_file) {
    $catalog = json_decode((string) file_get_contents($catalog_file), true);
    $name = basename($catalog_file);
    expect_error_page(
        is_array($catalog)
            && isset($catalog['not_found_title'], $catalog['not_found_message'])
            && isset($catalog['server_error_title'], $catalog['server_error_message']),
        "{$name} includes both error-document messages",
        $failures
    );
}

$htaccess = file_get_contents($root . '/src/.htaccess');
expect_error_page(
    is_string($htaccess)
        && str_contains($htaccess, 'ErrorDocument 404 /404.php')
        && str_contains($htaccess, 'ErrorDocument 500 /500.php'),
    'Apache routes 404/500 to the custom documents',
    $failures
);

$nginx = file_get_contents($root . '/deploy/apple-container/nginx.conf');
expect_error_page(
    is_string($nginx)
        && str_contains($nginx, 'error_page 404 /404.php;')
        && str_contains($nginx, 'error_page 500 502 503 504 /500.php;')
        && str_contains($nginx, 'try_files $uri $uri/ /404.php;'),
    'the container Nginx routes 404/5xx to the custom documents',
    $failures
);

$classic_nginx = file_get_contents($root . '/deploy/nginx/opng.conf');
expect_error_page(
    is_string($classic_nginx)
        && str_contains($classic_nginx, 'error_page 404 /404.php;')
        && str_contains($classic_nginx, 'error_page 500 502 503 504 /500.php;')
        && str_contains($classic_nginx, 'try_files $uri $uri/ /404.php;'),
    'the classic Nginx example routes 404/5xx to the custom documents',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Error-page contracts passed: status codes, localization, layout and server routing.\n";
