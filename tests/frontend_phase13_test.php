<?php

declare(strict_types=1);

/**
 * Progressive highlighting and editor contract test (10.8).
 *
 * Verifies the worker fallback, the virtualized line-number threshold, the
 * worker CSP directive and the reduced-motion/hidden CSS contracts.
 */

function frontend_phase13_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function frontend_phase13_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

function frontend_phase13_assert(bool $condition, string $message): void
{
    if (!$condition) {
        frontend_phase13_fail($message);
    }
    frontend_phase13_pass($message);
}

$root = dirname(__DIR__);
$worker = (string) file_get_contents($root . '/src/assets/js/highlight-worker.js');
frontend_phase13_assert($worker !== '', 'the highlighting worker exists');
frontend_phase13_assert(
    str_contains($worker, 'importScripts("../vendor/highlight.min.js")'),
    'the worker loads the same highlight.js bundle as the page'
);
frontend_phase13_assert(
    str_contains($worker, 'self.postMessage({') && str_contains($worker, 'ok: false'),
    'worker failures are reported to the main thread'
);
frontend_phase13_assert(
    str_contains($worker, 'highlightAuto') && str_contains($worker, 'getLanguage'),
    'the worker supports explicit and automatic languages'
);

$copy = (string) file_get_contents($root . '/src/assets/js/copy.js');
frontend_phase13_assert(
    str_contains($copy, 'new Worker("assets/js/highlight-worker.js")'),
    'the page starts the highlighting worker'
);
frontend_phase13_assert(
    str_contains($copy, 'highlightOnMainThread()') && str_contains($copy, 'worker.onerror = fallback'),
    'highlighting falls back to the main thread when the worker fails'
);
frontend_phase13_assert(
    str_contains($copy, 'worker.terminate()'),
    'workers are terminated after each request'
);

$gutter = (string) file_get_contents($root . '/src/assets/js/line-gutter.js');
frontend_phase13_assert(
    str_contains($gutter, 'const MIN_LINES = 1000;'),
    'line-number virtualization starts at 1,000 lines'
);
frontend_phase13_assert(
    str_contains($gutter, 'requestAnimationFrame') && str_contains($gutter, 'OVERSCAN'),
    'virtualized line numbers render on animation frames with an overscan'
);

$view = (string) file_get_contents($root . '/src/view.php');
frontend_phase13_assert(
    str_contains($view, 'assets/js/highlight-worker.js') || str_contains($view, 'assets/js/copy.js'),
    'the view wires the highlighting scripts'
);
frontend_phase13_assert(
    str_contains($view, 'assets/js/line-gutter.js'),
    'the view loads the line-number gutter script'
);
frontend_phase13_assert(
    str_contains($view, 'class="line-numbers"') && str_contains($view, 'data-i18n="line_numbers_hidden"'),
    'the server renders a single line-number block with a large-paste notice'
);

$header = (string) file_get_contents($root . '/src/includes/layout_header.php');
frontend_phase13_assert(
    str_contains($header, "worker-src 'self'"),
    'the response CSP allows same-origin workers'
);
$htaccess = (string) file_get_contents($root . '/src/.htaccess');
frontend_phase13_assert(
    str_contains($htaccess, "worker-src 'self'"),
    'the Apache CSP allows same-origin workers'
);
frontend_phase13_assert(
    !str_contains($header, "'unsafe-inline'"),
    'the CSP still forbids inline scripts and styles'
);

$css = (string) file_get_contents($root . '/src/main.css');
frontend_phase13_assert(
    str_contains($css, '[hidden] {') && str_contains($css, 'display: none !important'),
    'the global [hidden] rule keeps hidden controls hidden'
);
frontend_phase13_assert(
    str_contains($css, '@media (prefers-reduced-motion: reduce)'),
    'animations respect the reduced-motion preference'
);

$frontend_contract = (string) file_get_contents($root . '/tests/frontend_contract_test.php');
frontend_phase13_assert(
    str_contains($frontend_contract, "worker-src 'self'"),
    'the frontend contract test guards the worker CSP directive'
);

echo "Progressive frontend regression passed: worker, fallback, gutter, CSP and reduced motion.\n";
