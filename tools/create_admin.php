<?php
/**
 * Create the first administrator for a classic or container installation (F2).
 *
 * Usage (from the repository root or the artifact):
 *   php tools/create_admin.php --username=admin --email=admin@example.com
 *   OPNG_ADMIN_PASSWORD='...' php tools/create_admin.php --if-empty
 *   php tools/create_admin.php --print-sql --username=admin --email=...
 *
 * - CLI only, and uses the credentials from src/config.php.
 * - Without --password it prompts twice without echoing the secret.
 * - Refuses to create another administrator when one already exists unless
 *   --force is explicit. --if-empty is the idempotent container/entrypoint mode.
 * - --print-sql prints a prepared INSERT for phpMyAdmin/no-SSH fallback
 *   (generate it locally against a disposable/local database, then paste it).
 * - Passwords are never accepted through argv in documentation; prefer the
 *   OPNG_ADMIN_PASSWORD environment variable or the hidden prompt.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/password_validation.php';

/**
 * @return array<string, string|bool>
 */
function create_admin_options(array $argv): array
{
    $options = [
        'username' => '',
        'email' => '',
        'password' => '',
        'print-sql' => false,
        'force' => false,
        'if-empty' => false,
        'help' => false,
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--print-sql') {
            $options['print-sql'] = true;
        } elseif ($argument === '--force') {
            $options['force'] = true;
        } elseif ($argument === '--if-empty') {
            $options['if-empty'] = true;
        } elseif ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
        } elseif (str_starts_with($argument, '--username=')) {
            $options['username'] = substr($argument, 11);
        } elseif (str_starts_with($argument, '--email=')) {
            $options['email'] = substr($argument, 8);
        } elseif (str_starts_with($argument, '--password=')) {
            $options['password'] = substr($argument, 11);
        } else {
            throw new InvalidArgumentException('Unknown argument: ' . $argument);
        }
    }

    return $options;
}

function create_admin_usage(): never
{
    fwrite(
        STDERR,
        "Usage:\n"
        . "  php tools/create_admin.php --username=NAME --email=EMAIL\n"
        . "  OPNG_ADMIN_PASSWORD='...' php tools/create_admin.php --if-empty\n"
        . "  php tools/create_admin.php --print-sql --username=NAME --email=EMAIL\n"
        . "Options: --force, --if-empty, --print-sql, --help\n"
    );
    exit(2);
}

function create_admin_prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    $stty_state = null;
    if ($hidden && function_exists('shell_exec') && PHP_OS_FAMILY !== 'Windows') {
        $stty_state = shell_exec('stty -g 2>/dev/null');
        if (is_string($stty_state) && trim($stty_state) !== '') {
            shell_exec('stty -echo 2>/dev/null');
        } else {
            $stty_state = null;
        }
    }

    $value = fgets(STDIN);
    if ($stty_state !== null) {
        shell_exec('stty ' . escapeshellarg(trim($stty_state)) . ' 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    if ($value === false) {
        throw new RuntimeException('No input available for ' . strtolower(trim($label)) . '.');
    }

    return trim($value);
}

function create_admin_validate_username(string $username): ?string
{
    if (
        $username === ''
        || strlen($username) > 50
        || preg_match('//u', $username) !== 1
        || preg_match('/\p{C}/u', $username) === 1
    ) {
        return 'Username must be 1-50 bytes of printable UTF-8 text.';
    }

    return null;
}

function create_admin_sql_literal(mysqli $db, string $value): string
{
    return "'" . $db->real_escape_string($value) . "'";
}

try {
    $options = create_admin_options($argv);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    create_admin_usage();
}

if ($options['help'] === true) {
    create_admin_usage();
}

$username = trim((string) $options['username']);
$email = trim((string) $options['email']);
$password = (string) $options['password'];
$print_sql = (bool) $options['print-sql'];
$force = (bool) $options['force'];
$if_empty = (bool) $options['if-empty'];

try {
    if ($username === '') {
        $username = create_admin_prompt('Username: ');
    }
    if ($email === '') {
        $email = create_admin_prompt('Email: ');
    }

    $username_error = create_admin_validate_username($username);
    if ($username_error !== null) {
        throw new RuntimeException($username_error);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        throw new RuntimeException('Enter a valid email address (maximum 100 bytes).');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
    $db->set_charset('utf8mb4');

    // Check the account state before asking for a password: the container
    // --if-empty path must be safe and non-interactive on every restart.
    $admin_count_result = $db->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin'");
    $admin_count = (int) ($admin_count_result->fetch_assoc()['total'] ?? 0);
    $admin_count_result->free();
    if ($admin_count > 0 && !$force && !$print_sql) {
        $db->close();
        if ($if_empty) {
            echo "An administrator already exists; nothing to do.\n";
            exit(0);
        }
        throw new RuntimeException('An administrator already exists. Use --force only after reviewing the account list.');
    }

    // Only an interactive prompt asks for confirmation. Environment/argv
    // passwords are already explicit and allow unattended first boot.
    $password_is_interactive = false;
    if ($password === '') {
        $env_password = getenv('OPNG_ADMIN_PASSWORD');
        if (is_string($env_password) && $env_password !== '') {
            $password = $env_password;
        } else {
            $password = create_admin_prompt('Password: ', true);
            $password_is_interactive = true;
        }
    }
    $password_error = password_validation_error($password);
    if ($password_error !== null) {
        throw new RuntimeException('Choose a password between 12 and 72 bytes long.');
    }
    if ($password_is_interactive && !$print_sql) {
        $confirmation = create_admin_prompt('Confirm password: ', true);
        if (!hash_equals($password, $confirmation)) {
            throw new RuntimeException('The password confirmation does not match.');
        }
    }

    $existing = $db->prepare('SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
    $existing->bind_param('ss', $username, $email);
    $existing->execute();
    $existing_row = $existing->get_result()->fetch_assoc();
    $existing->close();
    if (is_array($existing_row)) {
        $db->close();
        throw new RuntimeException('That username or email is already registered.');
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    if ($print_sql) {
        $sql = 'INSERT INTO users (username, email, password_hash, role, session_version) VALUES ('
            . create_admin_sql_literal($db, $username) . ', '
            . create_admin_sql_literal($db, $email) . ', '
            . create_admin_sql_literal($db, $password_hash) . ", 'admin', 0);";
        $db->close();
        echo $sql . "\n";
        exit(0);
    }

    $insert = $db->prepare(
        "INSERT INTO users (username, email, password_hash, role, session_version)
         VALUES (?, ?, ?, 'admin', 0)"
    );
    $insert->bind_param('sss', $username, $email, $password_hash);
    $insert->execute();
    $insert->close();
    $db->close();

    echo "Administrator '{$username}' created.\n";
    echo "Sign in at /auth/login.php, then delete or protect tools/create_admin.php.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }
    exit(1);
}
