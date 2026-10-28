<?php
// database.php
// (v0.8) Conexión a la base de datos SIN migración automática.
// La creación de BD/tablas vive ahora en install.php y se ejecuta
// una sola vez al desplegar. Antes, cada visita ejecutaba DDL
// (CREATE DATABASE/TABLE) — coste innecesario en producción (P1).

require_once(__DIR__ . "/includes/db.php");

function database_connect(): ?mysqli {
    return db_connect();
}
?>