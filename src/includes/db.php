<?php

declare(strict_types=1);

// includes/db.php
// MySQL connection through MySQLi (P1/P3: no DDL on every connection).
// Database and table creation live in src/install.php.

require_once(__DIR__ . '/session.php');

/**
 * Connection timeout in seconds (ROB-19).
 *
 * A database that stops answering must not keep PHP-FPM workers blocked until
 * PHP's default socket timeout. OPNG_DB_CONNECT_TIMEOUT is validated to a
 * small range; invalid values fall back to five seconds.
 */
function db_connect_timeout_seconds(): int
{
    $raw = getenv('OPNG_DB_CONNECT_TIMEOUT');
    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);

    return $value === false ? 5 : $value;
}

/**
 * Optional read timeout in seconds (ROB-19).
 *
 * Unset by default: a global read timeout must be greater than the slowest
 * expected query (for example a large admin backup). Set OPNG_DB_READ_TIMEOUT
 * to bound a stuck query as well.
 */
function db_read_timeout_seconds(): ?int
{
    $raw = getenv('OPNG_DB_READ_TIMEOUT');
    if ($raw === false || $raw === '') {
        return null;
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);

    return $value === false ? null : $value;
}

function db_connect(): ?mysqli
{
    require(__DIR__ . "/../config.php");

    $conn = null;
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = mysqli_init();
        if (!$conn instanceof mysqli) {
            error_log('OPNG db.php: MySQL client initialization failed');
            return null;
        }
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, db_connect_timeout_seconds());
        $read_timeout = db_read_timeout_seconds();
        if ($read_timeout !== null && defined('MYSQLI_OPT_READ_TIMEOUT')) {
            $conn->options(MYSQLI_OPT_READ_TIMEOUT, $read_timeout);
        }
        $conn->real_connect($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
        $conn->set_charset('utf8mb4');

        // Validate the authenticated session against the database on every
        // request that opens a connection (SEC-02/ROB-04). A stale session
        // (password change, reset, role change, deleted account) loses its
        // identity before the page reads $_SESSION['user_id'].
        if (isset($_SESSION['user_id'])) {
            try {
                session_validate_account($conn);
            } catch (mysqli_sql_exception | RuntimeException $exception) {
                // Fail closed: if the account cannot be validated, drop the
                // authenticated identity instead of keeping stale privileges.
                // Public pages still render, and the user can sign in again
                // once the database recovers (SEC-20).
                error_log('OPNG db.php: session validation failed: ' . $exception->getMessage());
                session_forget_account();
            }
        }
    } catch (mysqli_sql_exception $exception) {
        if ($conn instanceof mysqli) {
            $conn->close();
        }
        error_log("OPNG db.php: MySQL connection failed: " . $exception->getMessage());
        return null;
    }

    return $conn;
}

// Alias kept for compatibility with existing code
// that still calls database_connect() from database.php.
// NOTE: since v0.8 THIS FUNCTION DOES NOT MIGRATE (see src/install.php).
