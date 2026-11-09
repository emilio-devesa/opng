<?php

declare(strict_types=1);

// includes/db.php
// MySQL connection through MySQLi (P1/P3: no DDL on every connection).
// Database and table creation live in src/install.php.

require_once(__DIR__ . '/session.php');

function db_connect(): ?mysqli
{
    require(__DIR__ . "/../config.php");

    $conn = null;
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
        $conn->set_charset('utf8mb4');

        // Validate the authenticated session against the database on every
        // request that opens a connection (SEC-02/ROB-04). A stale session
        // (password change, reset, role change, deleted account) loses its
        // identity before the page reads $_SESSION['user_id'].
        if (isset($_SESSION['user_id'])) {
            try {
                session_validate_account($conn);
            } catch (mysqli_sql_exception | RuntimeException $exception) {
                // A transient failure must not log everybody out; the DB-backed
                // pages will surface the outage through their own error paths.
                error_log('OPNG db.php: session validation failed: ' . $exception->getMessage());
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
