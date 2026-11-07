<?php
declare(strict_types=1);
// includes/doctor.php
// Startup configuration checklist (SEC-17).
//
// The doctor inspects the deployment without touching application data. The
// CLI wrapper is tools/doctor.php; container entrypoints and classic installs
// can run it before handing traffic to the web server.
//
// Checks are intentionally report-only: a failed check prints a clear action
// and makes the command exit non-zero, but the doctor never rewrites config.

const OPNG_DOCTOR_OK = 'ok';
const OPNG_DOCTOR_WARN = 'warn';
const OPNG_DOCTOR_FAIL = 'fail';
const OPNG_DOCTOR_SKIP = 'skip';

/**
 * Build one checklist entry.
 *
 * @return array{id: string, label: string, status: string, detail: string}
 */
function opng_doctor_result(string $id, string $label, string $status, string $detail): array
{
    return [
        'id' => $id,
        'label' => $label,
        'status' => $status,
        'detail' => $detail,
    ];
}

/**
 * Latest migration version present on disk.
 */
function opng_doctor_latest_migration(string $directory): ?string
{
    $files = glob(rtrim($directory, '/\\') . '/*.sql');
    if ($files === false || $files === []) {
        return null;
    }

    $latest = null;
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        if (preg_match('/\A\d{4}_[a-z0-9_]+\z/', $version) !== 1) {
            continue;
        }
        if ($latest === null || strcmp($version, $latest) > 0) {
            $latest = $version;
        }
    }

    return $latest;
}

/**
 * Whether the request/public URL uses TLS.
 *
 * Returns null when TLS cannot be inferred (for example, a CLI run without
 * OPNG_PUBLIC_URL or OPNG_FORCE_HTTPS).
 */
function opng_doctor_https_state(?string $public_url): ?bool
{
    if (is_string($public_url) && $public_url !== '') {
        $scheme = parse_url($public_url, PHP_URL_SCHEME);

        return is_string($scheme) && strtolower($scheme) === 'https';
    }

    if (PHP_SAPI === 'cli') {
        return getenv('OPNG_FORCE_HTTPS') === '1' ? true : null;
    }

    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/**
 * Run every deployment check.
 *
 * Context keys:
 *   config_path       ?string  absolute path to config.php
 *   config_password   string   runtime database password (never printed)
 *   database          array    connected, server_info, current_schema, applied
 *   migrations_dir    string   absolute path to database/migrations
 *   display_errors    string   effective display_errors value
 *   sapi              string   SAPI name (defaults to PHP_SAPI)
 *   dev_reset         ?string  effective OPNG_DEV_RESET value
 *   install_creds     bool     whether OPNG_INSTALL_DB_* is still populated
 *   https             ?bool    TLS state, or null when unknown
 *
 * @param array<string, mixed> $context
 * @return list<array{id: string, label: string, status: string, detail: string}>
 */
function opng_doctor_checks(array $context): array
{
    $results = [];
    $sapi = is_string($context['sapi'] ?? null) ? $context['sapi'] : PHP_SAPI;

    // Configuration file: present, readable, and not writable by the web user.
    $config_path = $context['config_path'] ?? null;
    $config_status = OPNG_DOCTOR_OK;
    $config_detail = 'src/config.php exists.';
    if (!is_string($config_path) || $config_path === '' || !is_file($config_path)) {
        $config_status = OPNG_DOCTOR_FAIL;
        $config_detail = 'src/config.php is missing; copy config.example.php and set the credentials.';
    } elseif (!is_readable($config_path)) {
        $config_status = OPNG_DOCTOR_FAIL;
        $config_detail = 'src/config.php is not readable by the application account.';
    } else {
        $perms = fileperms($config_path);
        if ($perms !== false) {
            $mode = $perms & 0777;
            if (($mode & 0022) !== 0) {
                $config_status = OPNG_DOCTOR_FAIL;
                $config_detail = sprintf(
                    'src/config.php is writable by group or others (mode %04o); run chmod 600 src/config.php.',
                    $mode
                );
            } elseif (($mode & 0044) !== 0) {
                $config_status = OPNG_DOCTOR_WARN;
                $config_detail = sprintf(
                    'src/config.php is readable by group or others (mode %04o); prefer chmod 600.',
                    $mode
                );
            }
        }
    }
    $results[] = opng_doctor_result('config_file', 'Configuration file', $config_status, $config_detail);

    // Database password: the shipped example is empty on purpose.
    $password = $context['config_password'] ?? '';
    $results[] = opng_doctor_result(
        'database_password',
        'Database password',
        is_string($password) && $password !== '' ? OPNG_DOCTOR_OK : OPNG_DOCTOR_FAIL,
        is_string($password) && $password !== ''
            ? 'A non-empty runtime database password is configured.'
            : 'OPNG_DB_PASS (or $mysql_password) is empty; set a unique runtime password.'
    );

    // Database connectivity and schema version.
    $database = is_array($context['database'] ?? null) ? $context['database'] : [];
    $connected = ($database['connected'] ?? false) === true;
    $results[] = opng_doctor_result(
        'database_connection',
        'Database connection',
        $connected ? OPNG_DOCTOR_OK : OPNG_DOCTOR_FAIL,
        $connected
            ? 'Connected to ' . (string) ($database['server_info'] ?? 'MySQL') . '.'
            : 'The database connection failed; check the host, account and grants.'
    );

    if (!$connected) {
        $results[] = opng_doctor_result(
            'schema_version',
            'Schema version',
            OPNG_DOCTOR_SKIP,
            'Skipped because the database connection failed.'
        );
    } else {
        $latest = opng_doctor_latest_migration((string) ($context['migrations_dir'] ?? ''));
        $current = $database['current_schema'] ?? null;
        $applied = is_array($database['applied'] ?? null) ? $database['applied'] : [];
        $schema_status = OPNG_DOCTOR_OK;
        $schema_detail = 'Schema version ' . (is_string($current) ? $current : 'none') . '.';

        if (!is_string($current) || $current === '') {
            $schema_status = OPNG_DOCTOR_FAIL;
            $schema_detail = 'No schema version is recorded; run php src/install.php or php tools/migrate.php.';
        } elseif ($latest !== null && strcmp($current, $latest) < 0) {
            $schema_status = OPNG_DOCTOR_FAIL;
            $schema_detail = "Schema is at {$current} but {$latest} is available; run php tools/migrate.php.";
        } elseif ($latest === null) {
            $schema_status = OPNG_DOCTOR_WARN;
            $schema_detail = 'No migration files were found; verify the deployment includes database/migrations.';
        } elseif (!in_array($latest, $applied, true)) {
            $schema_status = OPNG_DOCTOR_FAIL;
            $schema_detail = "Migration {$latest} is not recorded; run php tools/migrate.php.";
        }

        $results[] = opng_doctor_result('schema_version', 'Schema version', $schema_status, $schema_detail);
    }

    // display_errors must be off in the web SAPI. A CLI run cannot inspect the
    // FPM/Apache configuration, so an enabled CLI value is only a warning there.
    $display_errors = (string) ($context['display_errors'] ?? ini_get('display_errors'));
    $display_on = !in_array(strtolower($display_errors), ['', '0', 'off', 'false', 'no'], true);
    if (!$display_on) {
        $display_status = OPNG_DOCTOR_OK;
        $display_detail = 'display_errors is off.';
    } elseif ($sapi === 'cli') {
        $display_status = OPNG_DOCTOR_WARN;
        $display_detail = 'display_errors is on in this CLI; verify the web SAPI loads deploy/php/opng.ini.';
    } else {
        $display_status = OPNG_DOCTOR_FAIL;
        $display_detail = 'display_errors is on; set display_errors=Off for the web SAPI.';
    }
    $results[] = opng_doctor_result('display_errors', 'Error display', $display_status, $display_detail);

    // The reset simulation must never be enabled outside local development.
    $dev_reset = $context['dev_reset'] ?? getenv('OPNG_DEV_RESET');
    $dev_reset_on = is_string($dev_reset) && in_array($dev_reset, ['1', 'true', 'on', 'yes'], true);
    $results[] = opng_doctor_result(
        'dev_reset',
        'Password-reset simulation',
        $dev_reset_on ? OPNG_DOCTOR_FAIL : OPNG_DOCTOR_OK,
        $dev_reset_on
            ? 'OPNG_DEV_RESET is enabled; unset it before exposing the installation.'
            : 'OPNG_DEV_RESET is not enabled.'
    );

    // Installer credentials should be revoked after the schema is in place.
    $install_creds = ($context['install_creds'] ?? false) === true;
    $results[] = opng_doctor_result(
        'install_credentials',
        'Installer credentials',
        $install_creds ? OPNG_DOCTOR_WARN : OPNG_DOCTOR_OK,
        $install_creds
            ? 'OPNG_INSTALL_DB_* is still set; revoke the migration account and unset these variables.'
            : 'No installer credentials are exposed to the runtime.'
    );

    // TLS is required for __Host- cookies and HSTS.
    $https = $context['https'] ?? null;
    if ($https === true) {
        $https_status = OPNG_DOCTOR_OK;
        $https_detail = 'HTTPS is enabled.';
    } elseif ($https === false) {
        $https_status = OPNG_DOCTOR_FAIL;
        $https_detail = 'The public URL is plain HTTP; terminate TLS before production use.';
    } else {
        $https_status = OPNG_DOCTOR_WARN;
        $https_detail = 'TLS could not be inferred; set OPNG_PUBLIC_URL=https://... for a definitive check.';
    }
    $results[] = opng_doctor_result('https', 'HTTPS', $https_status, $https_detail);

    return $results;
}

/**
 * Count the checklist by status.
 *
 * @param list<array{status: string}> $results
 * @return array{ok: int, warn: int, fail: int, skip: int}
 */
function opng_doctor_summary(array $results): array
{
    $summary = [
        OPNG_DOCTOR_OK => 0,
        OPNG_DOCTOR_WARN => 0,
        OPNG_DOCTOR_FAIL => 0,
        OPNG_DOCTOR_SKIP => 0,
    ];
    foreach ($results as $result) {
        $status = $result['status'];
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    }

    return $summary;
}
