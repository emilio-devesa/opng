<?php

declare(strict_types=1);

// Container preflight. SHOW GRANTS can contain authentication metadata on
// MariaDB: never print a grant or include it in an exception message.
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = (string) getenv('OPNG_DB_NAME');
    if (!preg_match('/\A[A-Za-z0-9_]+\z/', $database)) {
        exit(1);
    }
    $db = new mysqli(
        (string) getenv('OPNG_DB_HOST'),
        (string) getenv('OPNG_DB_USER'),
        (string) getenv('OPNG_DB_PASS'),
        $database
    );
    $required = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
    $seen = [];
    foreach ($db->query('SHOW GRANTS')->fetch_all() as [$grant]) {
        if (stripos($grant, 'WITH GRANT OPTION') !== false) {
            exit(1);
        }
        if (str_starts_with($grant, 'GRANT USAGE ON *.* TO ')) {
            continue;
        }
        if (!preg_match('/^GRANT ([A-Z ,]+) ON `([^`]+)`\.\* TO /', $grant, $match)) {
            exit(1); // Includes global privileges, role grants and PROXY.
        }
        // A schema grant is a pattern: an underscore must be escaped.
        if ($match[2] !== str_replace('_', '\\_', $database)) {
            exit(1);
        }
        $privileges = explode(', ', $match[1]);
        if (array_diff($privileges, $required) !== []) {
            exit(1);
        }
        $seen = array_merge($seen, $privileges);
    }
    exit(array_diff($required, $seen) === [] ? 0 : 1);
} catch (Throwable) {
    exit(1);
}
