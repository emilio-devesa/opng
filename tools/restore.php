<?php
/**
 * Restore a portable SQL dump into the configured database (F2/F3).
 *
 * Usage (from the repository root or the artifact):
 *   php tools/restore.php --file=backup.sql [--checksum=backup.sql.sha256] [--force]
 *
 * - CLI only, and uses the credentials from src/config.php.
 * - Rejects dumps containing CREATE DATABASE or USE unless
 *   --allow-database-statements is passed; OPNG backups are portable.
 * - Verifies a .sha256 file when one is supplied.
 * - Asks for interactive confirmation (type RESTORE) unless --force is used.
 * - Uses the system mysql/mariadb client and a temporary option file
 *   with 0600 permissions; the password is never passed in argv.
 * - Restoring is destructive for the tables included in the dump.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';

/**
 * @return array<string, string|bool>
 */
function restore_options(array $argv): array
{
    $options = [
        'file' => '',
        'checksum' => '',
        'force' => false,
        'allow-database-statements' => false,
        'help' => false,
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--force') {
            $options['force'] = true;
        } elseif ($argument === '--allow-database-statements') {
            $options['allow-database-statements'] = true;
        } elseif ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
        } elseif (str_starts_with($argument, '--file=')) {
            $options['file'] = substr($argument, 7);
        } elseif (str_starts_with($argument, '--checksum=')) {
            $options['checksum'] = substr($argument, 11);
        } else {
            throw new InvalidArgumentException('Unknown argument: ' . $argument);
        }
    }

    return $options;
}

function restore_usage(): never
{
    fwrite(
        STDERR,
        "Usage:\n"
        . "  php tools/restore.php --file=backup.sql [--checksum=backup.sql.sha256] [--force]\n"
        . "Options: --allow-database-statements, --help\n"
    );
    exit(2);
}

/**
 * @return array{host: string, port: int|null, socket: string|null}
 */
function restore_server_parts(string $server): array
{
    if ($server === '') {
        return ['host' => 'localhost', 'port' => null, 'socket' => null];
    }
    if (str_starts_with($server, '/')) {
        return ['host' => 'localhost', 'port' => null, 'socket' => $server];
    }
    if (preg_match('/\A(.+):(\d+)\z/', $server, $matches) === 1) {
        return ['host' => $matches[1], 'port' => (int) $matches[2], 'socket' => null];
    }

    return ['host' => $server, 'port' => null, 'socket' => null];
}

function restore_mysql_client(): ?string
{
    $configured = getenv('OPNG_MYSQL_CLIENT');
    if (is_string($configured) && $configured !== '') {
        return is_executable($configured) ? $configured : null;
    }

    $directories = [
        '/usr/bin/',
        '/usr/local/bin/',
        '/opt/homebrew/bin/',
        '/opt/homebrew/opt/mysql-client/bin/',
        '/Applications/MAMP/Library/bin/mysql80/bin/',
        '/Applications/MAMP/Library/bin/mysql57/bin/',
        '/Applications/MAMP/Library/bin/',
    ];
    foreach (['mysql', 'mariadb'] as $candidate) {
        $path = shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null');
        if (is_string($path) && trim($path) !== '') {
            return trim($path);
        }
        foreach ($directories as $directory) {
            if (is_executable($directory . $candidate)) {
                return $directory . $candidate;
            }
        }
    }

    return null;
}

try {
    $options = restore_options($argv);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    restore_usage();
}

if ($options['help'] === true) {
    restore_usage();
}

$file = trim((string) $options['file']);
$checksum_file = trim((string) $options['checksum']);
$force = (bool) $options['force'];
$allow_database_statements = (bool) $options['allow-database-statements'];
if ($file === '') {
    fwrite(STDERR, "ERROR: --file=backup.sql is required.\n");
    restore_usage();
}
if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "ERROR: the backup file is not readable.\n");
    exit(1);
}
$file_size = filesize($file);
if ($file_size === false || $file_size === 0) {
    fwrite(STDERR, "ERROR: the backup file is empty.\n");
    exit(1);
}

$temporary_options = null;
try {
    if ($checksum_file !== '') {
        if (!is_file($checksum_file) || !is_readable($checksum_file)) {
            throw new RuntimeException('The checksum file is not readable.');
        }
        $checksum_contents = file_get_contents($checksum_file);
        if (!is_string($checksum_contents) || preg_match('/\A\s*([a-f0-9]{64})\b/i', $checksum_contents, $matches) !== 1) {
            throw new RuntimeException('The checksum file does not contain a SHA-256 digest.');
        }
        $expected = strtolower($matches[1]);
        $actual = hash_file('sha256', $file);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            throw new RuntimeException('The backup checksum does not match; refusing to restore.');
        }
        echo "Checksum verified.\n";
    }

    if (!$allow_database_statements) {
        $scan = fopen($file, 'rb');
        if ($scan === false) {
            throw new RuntimeException('The backup file could not be opened for inspection.');
        }
        $line_number = 0;
        $unsafe = false;
        while (($line = fgets($scan)) !== false) {
            $line_number++;
            if (preg_match('/^\s*(?:CREATE\s+DATABASE|USE)\b/i', $line) === 1) {
                $unsafe = true;
                break;
            }
        }
        fclose($scan);
        if ($unsafe) {
            throw new RuntimeException(
                'The dump contains CREATE DATABASE/USE at line ' . $line_number
                . '; use --allow-database-statements only after reviewing it.'
            );
        }
    }

    $server = restore_server_parts((string) $mysql_server);
    $target = (string) $mysql_dbname;
    if ($target === '') {
        throw new RuntimeException('The configured database name is empty.');
    }

    if (!$force) {
        fwrite(STDOUT, "This will overwrite tables in '{$target}' on {$server['host']}.\n");
        fwrite(STDOUT, 'Type RESTORE to continue: ');
        $confirmation = fgets(STDIN);
        if ($confirmation === false || trim($confirmation) !== 'RESTORE') {
            throw new RuntimeException('Restore cancelled.');
        }
    }

    $client = restore_mysql_client();
    if ($client === null) {
        throw new RuntimeException('No mysql/mariadb client was found in PATH.');
    }

    $temporary_options = tempnam(sys_get_temp_dir(), 'opng-restore-');
    if ($temporary_options === false) {
        $temporary_options = null;
        throw new RuntimeException('Could not create a temporary client options file.');
    }
    chmod($temporary_options, 0600);
    $options_contents = "[client]\n"
        . 'host=' . $server['host'] . "\n"
        . ($server['port'] !== null ? 'port=' . $server['port'] . "\n" : '')
        . ($server['socket'] !== null ? 'socket=' . $server['socket'] . "\n" : '')
        . 'user=' . (string) $mysql_username . "\n"
        . 'password=' . (string) $mysql_password . "\n";
    if (file_put_contents($temporary_options, $options_contents) !== strlen($options_contents)) {
        throw new RuntimeException('Could not write the temporary client options file.');
    }

    $input = fopen($file, 'rb');
    if ($input === false) {
        throw new RuntimeException('The backup file could not be opened for restore.');
    }

    $command = [$client, '--defaults-extra-file=' . $temporary_options, '--database=' . $target];
    $descriptors = [
        0 => $input,
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        fclose($input);
        throw new RuntimeException('The mysql client could not be started.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    fclose($input);
    $exit_code = proc_close($process);

    if (is_string($stdout) && trim($stdout) !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($exit_code !== 0) {
        $detail = is_string($stderr) && trim($stderr) !== '' ? trim($stderr) : 'unknown client error';
        throw new RuntimeException('Restore failed (exit ' . $exit_code . '): ' . $detail);
    }

    echo "Restored '{$target}' from {$file}.\n";
    echo "Next: run `php tools/migrate.php` and `php tools/doctor.php`, then perform a smoke test.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if (is_string($temporary_options) && is_file($temporary_options)) {
        @unlink($temporary_options);
    }
}
