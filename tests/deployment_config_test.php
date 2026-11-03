<?php

$root = dirname(__DIR__);
$failures = [];

function contains_all(string $label, string $source, array $markers, array &$failures): void
{
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            $failures[] = "{$label} is missing: {$marker}";
        }
    }
}

$htaccess_path = $root . '/src/.htaccess';
$htaccess = is_file($htaccess_path) ? file_get_contents($htaccess_path) : false;
if (!is_string($htaccess)) {
    $failures[] = 'src/.htaccess is missing';
} else {
    contains_all('src/.htaccess', $htaccess, [
        'RewriteRule ^includes',
        'RewriteRule ^install\\.php$',
        'Strict-Transport-Security',
        'Cache-Control "public, max-age=31536000, immutable"',
        'Options -Indexes',
    ], $failures);
}

$root_htaccess_path = $root . '/.htaccess';
$root_htaccess = is_file($root_htaccess_path) ? file_get_contents($root_htaccess_path) : false;
if (!is_string($root_htaccess)) {
    $failures[] = 'The repository-root .htaccess fallback is missing';
} else {
    contains_all('Repository-root .htaccess', $root_htaccess, [
        'RewriteRule ^src',
        'Options -Indexes',
    ], $failures);
}

$nginx = file_get_contents($root . '/deploy/nginx.example.conf');
if (!is_string($nginx)) {
    $failures[] = 'deploy/nginx.example.conf is missing';
} else {
    contains_all('deploy/nginx.example.conf', $nginx, [
        'location ^~ /includes/',
        'location = /install.php',
        'tests|tools|database|deploy|docs',
        'Strict-Transport-Security',
    ], $failures);
}

$readme = file_get_contents($root . '/README.md');
if (!is_string($readme) || !str_contains($readme, 'Use `src/` as the document root')) {
    $failures[] = 'README.md does not state the required document root';
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Deployment configuration contracts passed: Apache denies internal paths, root fallback exists, and Nginx denies development material.\n";
