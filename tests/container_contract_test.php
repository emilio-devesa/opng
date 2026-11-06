<?php
/**
 * Apple Container contracts (F3).
 *
 * Structural checks for the single-image arm64 release: Containerfile, Nginx,
 * PHP-FPM, supervisord, entrypoint, host scripts, build-context ignores, env
 * template and documentation. The test never needs a running container engine;
 * it fails when a security or orchestration invariant is removed.
 */

$root = dirname(__DIR__);
$container = $root . '/deploy/apple-container';
$failures = [];

function expect_container(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

function container_file(string $path, array &$failures): string
{
    $contents = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($contents)) {
        $failures[] = basename($path) . ' is missing or unreadable';
        return '';
    }

    return $contents;
}

foreach ([
    'Containerfile',
    'nginx.conf',
    'php-fpm.conf',
    'supervisord.conf',
    'entrypoint.sh',
    'healthcheck.sh',
    'start.sh',
    'stop.sh',
    'backup.sh',
    'restore.sh',
    'opng.env.example',
] as $file) {
    expect_container(is_file($container . '/' . $file), "deploy/apple-container/{$file} exists", $failures);
}

foreach (['entrypoint.sh', 'healthcheck.sh', 'start.sh', 'stop.sh', 'backup.sh', 'restore.sh'] as $script) {
    expect_container(
        is_executable($container . '/' . $script),
        "deploy/apple-container/{$script} is executable",
        $failures
    );
}

$dockerfile = container_file($container . '/Containerfile', $failures);
foreach ([
    'FROM php:8.3-fpm-alpine',
    'nginx',
    'supervisor',
    'mariadb-client',
    'docker-php-ext-install',
    'mysqli',
    'gd',
    'opcache',
    'mbstring',
    'rm -f /usr/local/etc/php-fpm.d/www.conf',
    '/usr/local/etc/php-fpm.d/docker.conf',
    '/usr/local/etc/php-fpm.d/zz-docker.conf',
    'rm -f /var/www/opng/src/config.php',
    'cp /var/www/opng/src/config.example.php /var/www/opng/src/config.php',
    'chmod 0600 /var/www/opng/src/config.php',
    'EXPOSE 8080',
    'HEALTHCHECK',
    'ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]',
] as $needle) {
    expect_container(str_contains($dockerfile, $needle), "Containerfile contains '{$needle}'", $failures);
}
expect_container(
    !str_contains($dockerfile, 'OPNG_DB_PASS=') && !str_contains($dockerfile, 'MARIADB_ROOT_PASSWORD='),
    'Containerfile bakes no database secrets into the image',
    $failures
);

$nginx = container_file($container . '/nginx.conf', $failures);
foreach ([
    'listen 8080;',
    'root /var/www/opng/src;',
    'fastcgi_pass 127.0.0.1:9000;',
    'fastcgi_param HTTPS off;',
    'client_max_body_size 4m;',
    'location ~ ^/(config\\.php|config\\.example\\.php|database\\.php|rules\\.xml)',
    'location ^~ /includes/',
    'location = /install.php',
    'location ~ ^/(tests|tools|database|deploy|docs)/',
    'location ~ /\\.(?!well-known)',
    'X-Content-Type-Options',
    'Strict-Transport-Security',
] as $needle) {
    expect_container(str_contains($nginx, $needle), "nginx.conf contains '{$needle}'", $failures);
}
expect_container(
    !str_contains($nginx, 'fastcgi_param HTTPS $') && !str_contains($nginx, 'listen 80;'),
    'nginx.conf serves unprivileged HTTP on 8080 and leaves HTTPS to the proxy',
    $failures
);

$fpm = container_file($container . '/php-fpm.conf', $failures);
foreach ([
    'listen = 127.0.0.1:9000',
    'listen.allowed_clients = 127.0.0.1',
    'clear_env = no',
    'pm.max_children = 12',
    'php_admin_value[display_errors] = off',
    'php_admin_flag[log_errors] = on',
    'php_admin_value[expose_php] = off',
    'php_admin_value[session.save_path] = /var/lib/php/sessions',
    'php_admin_value[post_max_size] = 4M',
    'php_admin_flag[allow_url_include] = off',
] as $needle) {
    expect_container(str_contains($fpm, $needle), "php-fpm.conf contains '{$needle}'", $failures);
}

$supervisor = container_file($container . '/supervisord.conf', $failures);
foreach (['[program:php-fpm]', '[program:nginx]', 'autorestart = true', 'stdout_logfile = /dev/stdout'] as $needle) {
    expect_container(str_contains($supervisor, $needle), "supervisord.conf contains '{$needle}'", $failures);
}

$entrypoint = container_file($container . '/entrypoint.sh', $failures);
foreach ([
    'set -eu',
    'umask 027',
    'OPNG_DB_PASS is empty',
    'mariadb-admin ping',
    'OPNG_AUTO_MIGRATE',
    'php src/install.php',
    'OPNG_INSTALL_DB_USER',
    'php tools/create_admin.php',
    '--if-empty',
    'OPNG_ADMIN_USERNAME',
    'OPNG_ADMIN_EMAIL',
    'OPNG_ADMIN_PASSWORD',
    'unset OPNG_ADMIN_USERNAME OPNG_ADMIN_EMAIL OPNG_ADMIN_PASSWORD',
    'exec supervisord',
] as $needle) {
    expect_container(str_contains($entrypoint, $needle), "entrypoint.sh contains '{$needle}'", $failures);
}
expect_container(
    !str_contains($entrypoint, 'OPNG_DB_ROOT_PASSWORD'),
    'entrypoint.sh never reads the MariaDB root password',
    $failures
);

$healthcheck = container_file($container . '/healthcheck.sh', $failures);
expect_container(
    str_contains($healthcheck, '/healthz.php') && str_contains($healthcheck, '"status":"ok"'),
    'healthcheck.sh requires /healthz.php to report status ok',
    $failures
);

$start = container_file($container . '/start.sh', $failures);
foreach ([
    'container network create',
    'container volume create',
    'container run -d --name "$db_container"',
    'mariadb:11',
    '--volume "${db_volume}:/var/lib/mysql"',
    'container build -t "$image"',
    '--publish "$publish"',
    '127.0.0.1:8080:8080',
    '--read-only',
    '--tmpfs /tmp',
    '--tmpfs /run',
    '--tmpfs /var/lib/nginx',
    '--tmpfs /var/lib/php/sessions',
    'grep -v \'^OPNG_DB_ROOT_PASSWORD=\'',
    'chmod 600 "$app_env"',
    'healthcheck.sh',
] as $needle) {
    expect_container(str_contains($start, $needle), "start.sh contains '{$needle}'", $failures);
}
expect_container(
    !str_contains($start, '--publish 3306') && !str_contains($start, '"${OPNG_DB_ROOT_PASSWORD}" "$app_container"'),
    'start.sh keeps MariaDB private and filters the root password from the app',
    $failures
);

$backup = container_file($container . '/backup.sh', $failures);
expect_container(
    str_contains($backup, 'php tools/backup.php')
    && str_contains($backup, 'sha256sum')
    && str_contains($backup, '/backups/')
    && str_contains($backup, '*[!A-Za-z0-9._-]*'),
    'backup.sh writes a checksummed dump and validates the file name',
    $failures
);

$restore = container_file($container . '/restore.sh', $failures);
expect_container(
    str_contains($restore, 'container cp')
    && str_contains($restore, 'php tools/restore.php')
    && str_contains($restore, '--checksum=')
    && str_contains($restore, '--force'),
    'restore.sh copies the dump, verifies its checksum and optionally skips confirmation',
    $failures
);

$stop = container_file($container . '/stop.sh', $failures);
expect_container(
    str_contains($stop, 'container stop')
    && str_contains($stop, '--remove')
    && str_contains($stop, 'volumes are preserved'),
    'stop.sh never deletes volumes',
    $failures
);

$env = container_file($container . '/opng.env.example', $failures);
foreach ([
    'OPNG_DB_HOST=opng-db',
    'OPNG_DB_NAME=opng',
    'OPNG_DB_USER=opng',
    'OPNG_DB_PASS=',
    'OPNG_DB_ROOT_PASSWORD=',
    'OPNG_FORCE_HTTPS=',
    'OPNG_AUTO_MIGRATE=1',
    'OPNG_ADMIN_USERNAME=',
    'OPNG_ADMIN_EMAIL=',
    'OPNG_ADMIN_PASSWORD=',
] as $needle) {
    expect_container(str_contains($env, $needle), "opng.env.example contains '{$needle}'", $failures);
}
expect_container(
    str_contains($env, 'change-me'),
    'opng.env.example uses change-me placeholders, never real secrets',
    $failures
);
expect_container(
    !is_file($container . '/opng.env'),
    'deploy/apple-container/opng.env (real secrets) is not committed',
    $failures
);

foreach ([$root . '/.containerignore', $root . '/.dockerignore'] as $ignore) {
    $contents = container_file($ignore, $failures);
    foreach ([
        'src/config.php',
        'deploy/apple-container/opng.env',
        '!deploy/apple-container/opng.env.example',
        '.git',
        'tests',
        'vendor',
        'composer.json',
        'phpstan.neon',
    ] as $needle) {
        expect_container(
            str_contains($contents, $needle),
            basename($ignore) . " excludes '{$needle}' from the build context",
            $failures
        );
    }
}

$docs = container_file($root . '/docs/APPLE-CONTAINER.md', $failures);
foreach ([
    'container system start',
    'opng.env.example',
    '127.0.0.1:8080',
    'OPNG_FORCE_HTTPS',
    'OPNG_AUTO_MIGRATE',
    '--read-only',
    'opng-db-data',
    'backup.sh',
    'restore.sh',
    'container logs',
    'arm64',
] as $needle) {
    expect_container(str_contains($docs, $needle), "APPLE-CONTAINER.md documents '{$needle}'", $failures);
}

foreach ([
    'docs/BACKUP-RESTORE.md' => ['opng-db-data', 'container exec opng-app'],
    'docs/SECURITY.md' => ['--read-only', 'opng-net'],
    'docs/UPGRADING.md' => ['APPLE-CONTAINER.md', 'opng-db-data'],
] as $doc => $needles) {
    $contents = container_file($root . '/' . $doc, $failures);
    foreach ($needles as $needle) {
        expect_container(
            str_contains($contents, $needle),
            "{$doc} documents the container workflow ('{$needle}')",
            $failures
        );
    }
}

$container_workflow = container_file($root . '/.github/workflows/container.yml', $failures);
expect_container(
    str_contains($container_workflow, 'linux/arm64')
    && str_contains($container_workflow, 'deploy/apple-container/Containerfile')
    && str_contains($container_workflow, 'sbom: true')
    && str_contains($container_workflow, 'provenance: true'),
    'the container workflow publishes an arm64 image with SBOM and provenance',
    $failures
);

$sh = shell_exec('command -v sh 2>/dev/null');
if (is_string($sh) && trim($sh) !== '') {
    foreach (['entrypoint.sh', 'healthcheck.sh', 'start.sh', 'stop.sh', 'backup.sh', 'restore.sh'] as $script) {
        $output = [];
        $exit_code = 0;
        exec(
            'sh -n ' . escapeshellarg($container . '/' . $script) . ' 2>&1',
            $output,
            $exit_code
        );
        expect_container(
            $exit_code === 0,
            "deploy/apple-container/{$script} has valid POSIX shell syntax",
            $failures
        );
    }
} else {
    echo "SKIP: sh is not available; skipping shell syntax checks.\n";
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Container contracts passed: image, configs, scripts, ignores and docs.\n";
