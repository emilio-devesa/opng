<?php
// database.php
// Database connection WITHOUT automatic migration.
// Database and table creation now lives in install.php and runs
// once at deploy time. Previously every request executed DDL
// (CREATE DATABASE/TABLE) — unnecessary cost in production (P1).

require_once(__DIR__ . "/includes/db.php");

function database_connect(): ?mysqli {
    return db_connect();
}
?>