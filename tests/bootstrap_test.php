<?php

/**
 * Bootstrap and autoloader contracts (HYG-08).
 *
 * Entry points must load the shared foundation through src/bootstrap.php, and
 * the lightweight autoloader must resolve Opng\ classes without an explicit
 * require. Tests point OPNG_CLASSES_DIR at a temporary directory so they never
 * write into src/classes/.
 */

$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/opng-bootstrap-' . bin2hex(random_bytes(6));
mkdir($temp . '/Demo', 0700, true);
file_put_contents(
    $temp . '/Demo/Probe.php',
    "<?php\nnamespace Opng\\Demo;\nfinal class Probe { public static function value(): string { return 'loaded'; } }\n"
);

define('OPNG_CLASSES_DIR', $temp);
require $root . '/src/bootstrap.php';

$failures = [];

function expect_bootstrap(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

expect_bootstrap(
    class_exists('Opng\\Demo\\Probe')
        && \Opng\Demo\Probe::value() === 'loaded',
    'the autoloader resolves Opng\\ classes from OPNG_CLASSES_DIR',
    $failures
);
expect_bootstrap(
    opng_class_path('Opng\\Demo\\Probe') === $temp . '/Demo/Probe.php',
    'class names map to nested class files',
    $failures
);
expect_bootstrap(opng_class_path('Other\\Probe') === null, 'foreign namespaces are ignored', $failures);
expect_bootstrap(opng_class_path('Opng\\Missing') === null, 'missing classes resolve to null', $failures);
expect_bootstrap(opng_class_path('Opng\\../Escape') === null, 'path traversal in class names is rejected', $failures);

foreach ([
    'session_start_secured',
    'csrf_token',
    'database_connect',
    'http_string_parameter',
    'opng_error_text',
    'opng_current_locale',
    'opng_asset_version',
    'opng_icon',
    'opng_hash_identifier',
    'opng_send_request_id_header',
] as $function) {
    expect_bootstrap(function_exists($function), "bootstrap loads {$function}()", $failures);
}

$bootstrap = file_get_contents($root . '/src/bootstrap.php');
expect_bootstrap(
    is_string($bootstrap)
        && str_contains($bootstrap, 'spl_autoload_register')
        && str_contains($bootstrap, "define('OPNG_CLASSES_DIR'")
        && str_contains($bootstrap, "require_once __DIR__ . '/includes/db.php'"),
    'bootstrap registers the autoloader and loads the foundation libraries',
    $failures
);

foreach ([
    'src/index.php' => "require_once __DIR__ . '/bootstrap.php';",
    'src/view.php' => "require_once __DIR__ . '/bootstrap.php';",
    'src/auth/login.php' => "require_once __DIR__ . '/../bootstrap.php';",
    'src/admin/admin.php' => "require_once __DIR__ . '/../bootstrap.php';",
    'src/api/v1/index.php' => "require_once __DIR__ . '/../../bootstrap.php';",
] as $path => $expected) {
    $source = file_get_contents($root . '/' . $path);
    expect_bootstrap(
        is_string($source)
            && str_contains($source, $expected)
            && !str_contains($source, 'includes/session.php')
            && !str_contains($source, 'includes/csrf.php')
            && !str_contains($source, 'includes/db.php'),
        "{$path} uses the shared bootstrap instead of repeating foundation requires",
        $failures
    );
}

foreach (glob($temp . '/Demo/*') ?: [] as $file) {
    unlink($file);
}
rmdir($temp . '/Demo');
rmdir($temp);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Bootstrap contracts passed: autoloader, foundation libraries and entry-point adoption.\n";
