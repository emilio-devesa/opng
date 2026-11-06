<?php
/**
 * Classic release contracts (F2).
 *
 * Pure PHP checks for the release tooling, docs and server configs, plus a
 * real build into a temporary directory that verifies the package contents,
 * exclusions and SHA-256 sums. The build uses PHP, zip and tar only; it does
 * not need Composer, Node or a database.
 */

$root = dirname(__DIR__);
$failures = [];

function expect_release(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

foreach ([
    'tools/install.php',
    'tools/create_admin.php',
    'tools/restore.php',
    'tools/build_release.sh',
    'database/schema.sql',
    'docs/INSTALL-CLASSIC.md',
    'docs/UPGRADING.md',
    'docs/BACKUP-RESTORE.md',
    'docs/SECURITY.md',
    'deploy/apache/opng.conf',
    'deploy/apache/htaccess',
    'deploy/nginx/opng.conf',
    'deploy/php/opng.ini',
    '.github/workflows/release.yml',
    '.github/workflows/container.yml',
] as $relative) {
    expect_release(
        is_file($root . '/' . $relative),
        "release file {$relative} exists",
        $failures
    );
}

foreach (['install.php', 'create_admin.php', 'restore.php'] as $tool) {
    $source = file_get_contents($root . '/tools/' . $tool);
    expect_release(
        is_string($source) && str_contains($source, "PHP_SAPI !== 'cli'"),
        "tools/{$tool} refuses web execution",
        $failures
    );
}

$install_wrapper = file_get_contents($root . '/tools/install.php');
expect_release(
    is_string($install_wrapper)
    && str_contains($install_wrapper, "require __DIR__ . '/../src/install.php'"),
    'tools/install.php delegates to the canonical installer',
    $failures
);

$create_admin = file_get_contents($root . '/tools/create_admin.php');
expect_release(
    is_string($create_admin)
    && str_contains($create_admin, '--if-empty')
    && str_contains($create_admin, '--print-sql')
    && str_contains($create_admin, 'OPNG_ADMIN_PASSWORD')
    && str_contains($create_admin, 'An administrator already exists')
    && str_contains($create_admin, 'password_validation_error')
    && !str_contains($create_admin, 'shell_exec(\'stty echo\')'),
    'create_admin supports safe unattended provisioning and SQL fallback',
    $failures
);

$restore = file_get_contents($root . '/tools/restore.php');
expect_release(
    is_string($restore)
    && str_contains($restore, '--allow-database-statements')
    && str_contains($restore, 'CREATE\\s+DATABASE')
    && str_contains($restore, 'hash_file(\'sha256\'')
    && str_contains($restore, 'Type RESTORE to continue')
    && str_contains($restore, 'OPNG_MYSQL_CLIENT')
    && str_contains($restore, 'chmod($temporary_options, 0600)')
    && !str_contains($restore, '--password='),
    'restore verifies checksums, rejects database statements and never puts the password in argv',
    $failures
);

$build = file_get_contents($root . '/tools/build_release.sh');
expect_release(
    is_string($build)
    && str_contains($build, 'opng-${version}-classic.zip')
    && str_contains($build, 'opng-${version}-classic.tar.gz')
    && str_contains($build, 'src/config.php must never be packaged')
    && str_contains($build, 'rm -f "$stage/src/config.php"')
    && str_contains($build, 'SHA256SUMS')
    && str_contains($build, 'rm -f "$stage/tools/seed.php"')
    && is_executable($root . '/tools/build_release.sh'),
    'the release builder produces archives, excludes secrets and is executable',
    $failures
);

$apache = file_get_contents($root . '/deploy/apache/opng.conf');
expect_release(
    is_string($apache)
    && str_contains($apache, 'DocumentRoot /var/www/opng/src')
    && str_contains($apache, 'AllowOverride All')
    && str_contains($apache, 'Options -Indexes')
    && str_contains($apache, 'Require all denied')
    && str_contains($apache, 'LimitRequestBody 4194304')
    && str_contains($apache, 'Strict-Transport-Security')
    && str_contains($apache, 'proxy:unix:'),
    'the Apache example hardens the document root, body size and headers',
    $failures
);
$apache_htaccess = file_get_contents($root . '/deploy/apache/htaccess');
expect_release(
    is_string($apache_htaccess)
    && str_contains($apache_htaccess, 'includes')
    && str_contains($apache_htaccess, 'config\\.php')
    && str_contains($apache_htaccess, 'tests|tools|database|deploy|docs')
    && str_contains($apache_htaccess, 'Options -Indexes'),
    'the deployable htaccess denies internal paths',
    $failures
);

$nginx = file_get_contents($root . '/deploy/nginx/opng.conf');
expect_release(
    is_string($nginx)
    && str_contains($nginx, 'client_max_body_size 4m')
    && str_contains($nginx, 'location ^~ /includes/')
    && str_contains($nginx, 'location = /install.php')
    && str_contains($nginx, 'well-known')
    && str_contains($nginx, 'fastcgi_pass')
    && str_contains($nginx, 'healthz.php'),
    'the Nginx example denies internal paths and documents the health endpoint',
    $failures
);

$schema = file_get_contents($root . '/database/schema.sql');
expect_release(
    is_string($schema)
    && str_contains($schema, "INSERT IGNORE INTO schema_migrations (version) VALUES")
    && str_contains($schema, "('0004_entry_threads')")
    && str_contains($schema, 'CONSTRAINT fk_entries_parent'),
    'the canonical schema records every migration for the phpMyAdmin path',
    $failures
);

$release_workflow = file_get_contents($root . '/.github/workflows/release.yml');
expect_release(
    is_string($release_workflow)
    && str_contains($release_workflow, "tags:")
    && str_contains($release_workflow, "'v*'")
    && str_contains($release_workflow, 'tools/build_release.sh')
    && str_contains($release_workflow, 'SHA256SUMS')
    && str_contains($release_workflow, 'softprops/action-gh-release')
    && str_contains($release_workflow, 'mysql:8.0')
    && str_contains($release_workflow, 'tools/create_admin.php')
    && str_contains($release_workflow, 'healthz.php'),
    'the release workflow tests, smoke-tests and publishes checksummed artifacts',
    $failures
);

$container_workflow = file_get_contents($root . '/.github/workflows/container.yml');
expect_release(
    is_string($container_workflow)
    && str_contains($container_workflow, 'linux/arm64')
    && str_contains($container_workflow, 'ghcr.io')
    && str_contains($container_workflow, 'deploy/apple-container/Containerfile')
    && str_contains($container_workflow, 'sbom'),
    'the container workflow builds and publishes the arm64 image with an SBOM',
    $failures
);

foreach ([
    'docs/INSTALL-CLASSIC.md' => ['tools/install.php', 'tools/create_admin.php', 'phpMyAdmin', 'OPNG_FORCE_HTTPS'],
    'docs/UPGRADING.md' => ['tools/backup.php', 'tools/migrate.php', '0004_entry_threads', 'Rollback'],
    'docs/BACKUP-RESTORE.md' => ['CREATE DATABASE', 'tools/restore.php', 'checksum'],
    'docs/SECURITY.md' => ['tools/doctor.php', 'OPNG_TRUSTED_PROXIES', 'Reporting a vulnerability'],
] as $doc => $needles) {
    $contents = file_get_contents($root . '/' . $doc);
    foreach ($needles as $needle) {
        expect_release(
            is_string($contents) && str_contains($contents, $needle),
            "{$doc} documents '{$needle}'",
            $failures
        );
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

foreach (['zip', 'tar', 'bash'] as $command) {
    $path = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
    if (!is_string($path) || trim($path) === '') {
        fwrite(STDERR, "SKIP: {$command} is not available; skipping the archive build check.\n");
        exit(0);
    }
}

$temporary = sys_get_temp_dir() . '/opng-release-' . bin2hex(random_bytes(4));
$version = '0.0.0-test';
$command = [
    'bash',
    $root . '/tools/build_release.sh',
    '--version',
    $version,
    '--output',
    $temporary,
];
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$environment = getenv();
$environment['PATH'] = dirname(PHP_BINARY) . ':' . ($environment['PATH'] ?? '');
$process = proc_open($command, $descriptors, $pipes, $root, $environment);
if (!is_resource($process)) {
    fwrite(STDERR, "FAIL: the release builder could not be started.\n");
    exit(1);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit_code = proc_close($process);
if ($exit_code !== 0) {
    fwrite(STDERR, "FAIL: the release builder failed:\n{$stdout}\n{$stderr}\n");
    exit(1);
}

$zip_path = $temporary . '/opng-' . $version . '-classic.zip';
$tar_path = $temporary . '/opng-' . $version . '-classic.tar.gz';
$sums_path = $temporary . '/SHA256SUMS';
foreach ([$zip_path, $tar_path, $sums_path] as $artifact) {
    expect_release(is_file($artifact) && filesize($artifact) > 0, basename($artifact) . ' was built', $failures);
}

$sums = is_file($sums_path) ? file_get_contents($sums_path) : '';
if (is_string($sums)) {
    foreach ([$zip_path, $tar_path] as $artifact) {
        $basename = basename($artifact);
        $expected = hash_file('sha256', $artifact);
        expect_release(
            is_string($expected)
            && preg_match('/^' . preg_quote($expected, '/') . '\s+' . preg_quote($basename, '/') . '$/m', $sums) === 1,
            $basename . ' matches the published SHA-256 sum',
            $failures
        );
    }
}

$zip_listing = shell_exec('unzip -Z1 ' . escapeshellarg($zip_path) . ' 2>/dev/null');
$tar_listing = shell_exec('tar -tzf ' . escapeshellarg($tar_path) . ' 2>/dev/null');
expect_release(
    is_string($zip_listing) && str_contains($zip_listing, 'opng-' . $version . '/src/config.example.php'),
    'the zip contains the configuration template',
    $failures
);
expect_release(
    is_string($tar_listing) && str_contains($tar_listing, 'opng-' . $version . '/database/schema.sql'),
    'the tarball contains the canonical schema',
    $failures
);
foreach (['README.md', 'CHANGELOG.md', 'LICENSE', 'SHA256SUMS', 'tools/install.php', 'docs/INSTALL-CLASSIC.md', 'deploy/apache/opng.conf', 'deploy/nginx/opng.conf'] as $required) {
    expect_release(
        is_string($zip_listing)
        && str_contains($zip_listing, 'opng-' . $version . '/' . $required),
        "the package includes {$required}",
        $failures
    );
}
foreach (['/tests/', '/.github/', '/composer.json', '/composer.lock', '/phpstan.neon', '/screenshots/', '/src/config.php', '/.git/', '/tools/seed.php', '/deploy/apple-container/'] as $excluded) {
    expect_release(
        is_string($zip_listing) && !str_contains($zip_listing, $excluded),
        "the package excludes {$excluded}",
        $failures
    );
}

shell_exec('rm -rf ' . escapeshellarg($temporary));

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Release contracts passed: tools, docs, server configs, packaged contents and checksums.\n";
