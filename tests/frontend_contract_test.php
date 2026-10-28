<?php

$root = dirname(__DIR__);
$languages = ['en', 'es', 'de', 'fr', 'pt', 'zh'];
$catalogs = [];
$failures = [];

foreach ($languages as $language) {
    $path = $root . '/src/assets/lang/' . $language . '.json';
    $contents = file_get_contents($path);
    if ($contents === false) {
        $failures[] = "Missing translation catalog: {$language}.json";
        continue;
    }

    $catalog = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($catalog)) {
        $failures[] = "Translation catalog is not an object: {$language}.json";
        continue;
    }
    $catalogs[$language] = $catalog;
}

$reference_keys = array_keys($catalogs['en'] ?? []);
sort($reference_keys);
foreach ($catalogs as $language => $catalog) {
    $keys = array_keys($catalog);
    sort($keys);
    if ($keys !== $reference_keys) {
        $failures[] = "Translation keys differ in {$language}.json";
    }
    foreach ($catalog as $key => $value) {
        if (!is_string($value) || trim($value) === '') {
            $failures[] = "Translation {$language}.json:{$key} must be a non-empty string";
        }
    }
}

$required_keys = [
    'copy_error',
    'delete_confirm',
    'language_selector_label',
    'password_policy',
    'pagination',
    'reset_unavailable',
];
foreach ($required_keys as $key) {
    if (!array_key_exists($key, $catalogs['en'] ?? [])) {
        $failures[] = "Required translation key is missing: {$key}";
    }
}

$source = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($source as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    if ($contents === false) {
        $failures[] = "Could not read source file: {$file->getPathname()}";
        continue;
    }
    if (preg_match('/\s(?:on[a-z]+|style)\s*=/i', $contents) === 1) {
        $failures[] = "Inline event/style attribute found in {$file->getPathname()}";
    }
}

foreach ([$root . '/src/.htaccess', $root . '/deploy/nginx.example.conf'] as $policy_file) {
    $policy = file_get_contents($policy_file);
    if ($policy === false) {
        $failures[] = "Could not read CSP configuration: {$policy_file}";
        continue;
    }
    if (preg_match('/Content-Security-Policy[^\r\n]*unsafe-inline/i', $policy) === 1) {
        $failures[] = "CSP still permits unsafe-inline: {$policy_file}";
    }
    if (preg_match("/Content-Security-Policy[^\\r\\n]*style-src 'self'; script-src 'self'/", $policy) !== 1) {
        $failures[] = "CSP is missing same-origin style/script restrictions: {$policy_file}";
    }
}

$layout = file_get_contents($root . '/src/includes/layout_header.php');
if (!is_string($layout) || preg_match('/Content-Security-Policy:[^"]*style-src \'self\'; script-src \'self\'/', $layout) !== 1) {
    $failures[] = 'Shared application layout is missing the strict same-origin CSP';
}

$highlighter = $root . '/src/assets/vendor/highlight.min.js';
$license = $root . '/src/assets/vendor/HIGHLIGHT-LICENSE.txt';
if (!is_file($highlighter) || filesize($highlighter) < 1000) {
    $failures[] = 'Local Highlight.js bundle is missing or unexpectedly small';
}
$license_contents = is_file($license) ? file_get_contents($license) : false;
if (!is_string($license_contents) || !str_contains($license_contents, 'BSD 3-Clause License')) {
    $failures[] = 'Highlight.js BSD-3-Clause license is missing';
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo 'Frontend contracts passed: translation parity, no inline attributes, strict CSP, and local vendor license.' . PHP_EOL;
