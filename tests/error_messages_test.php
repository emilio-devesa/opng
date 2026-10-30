<?php

require __DIR__ . '/../src/includes/error_messages.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$languages = ['en', 'es', 'de', 'fr', 'pt', 'zh'];
$catalogs = [];

foreach ($languages as $language) {
    $path = $root . '/src/assets/lang/' . $language . '.json';
    $contents = file_get_contents($path);
    assert_true(is_string($contents), "catalog {$language}.json is readable");

    $catalog = json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);
    assert_true(is_array($catalog), "catalog {$language}.json is a JSON object");
    $catalogs[$language] = $catalog;
}

foreach (OPNG_ERROR_KEYS as $key) {
    foreach ($catalogs as $language => $catalog) {
        assert_true(
            isset($catalog[$key]) && is_string($catalog[$key]) && trim($catalog[$key]) !== '',
            "{$language}.json contains a non-empty translation for {$key}"
        );
    }
}

assert_true(opng_error_key('error_csrf') === 'error_csrf', 'known error keys are preserved');
assert_true(opng_error_key('not_a_key') === 'generic_error', 'unknown error keys fall back to generic_error');
assert_true(opng_error_key('') === 'generic_error', 'an empty error key falls back to generic_error');
assert_true(
    opng_error_text('error_empty_text') === 'The text cannot be empty.',
    'the server-side fallback renders the English catalog text'
);
assert_true(
    opng_error_text('generic_error') === 'An error occurred.',
    'the generic fallback is rendered server-side'
);

// Every literal key passed to opng_error_redirect() must be in the allowlist.
$source = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($source as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if (!is_string($contents)) {
        continue;
    }

    if (preg_match('/error\.php\?msg="\s*\.\s*urlencode/', $contents) === 1) {
        assert_true(false, 'legacy text-based error redirect found in ' . $file->getPathname());
    }

    if (preg_match_all("/opng_error_redirect\\(\\s*'([^']+)'/", $contents, $matches) > 0) {
        foreach ($matches[1] as $key) {
            assert_true(
                in_array($key, OPNG_ERROR_KEYS, true),
                "redirect key {$key} in " . $file->getFilename() . ' is in the allowlist'
            );
        }
    }
}

echo "Error message contracts passed: catalogs, fallback text, and redirect keys." . PHP_EOL;
