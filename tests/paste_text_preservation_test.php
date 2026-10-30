<?php

require __DIR__ . '/../src/includes/http_input.php';
require __DIR__ . '/../src/includes/paste_validation.php';

function assert_same_value(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

$text = "    indented first line\nsecond line\n";
assert_same_value(
    $text,
    http_string_parameter(['text' => $text], 'text', 100, false),
    'trim=false preserves leading indentation and the trailing newline'
);
assert_same_value(
    trim($text),
    http_string_parameter(['text' => $text], 'text', 100),
    'the default trim behavior is unchanged for other fields'
);
assert_same_value(
    null,
    paste_validation_error($text, 'topic'),
    'a paste with leading indentation and a trailing newline is valid'
);
assert_same_value(
    'error_empty_text',
    paste_validation_error(" \n\t", 'topic'),
    'whitespace-only paste content is still rejected'
);

$root = dirname(__DIR__);
foreach (['submit.php', 'update.php'] as $file) {
    $contents = file_get_contents($root . '/src/' . $file);
    if (!is_string($contents)) {
        fwrite(STDERR, "FAIL: could not read src/{$file}\n");
        exit(1);
    }

    $preserves = preg_match(
        '/http_string_parameter\(\s*\$_POST,\s*[\'"]input_text[\'"],\s*OPNG_MAX_PASTE_BYTES\s*\+\s*1,\s*false\s*\)/',
        $contents
    ) === 1;
    if (!$preserves) {
        fwrite(STDERR, "FAIL: src/{$file} does not disable trimming for input_text\n");
        exit(1);
    }

    echo "PASS: src/{$file} disables trimming for input_text\n";
}

echo "Paste text preservation contracts passed." . PHP_EOL;
