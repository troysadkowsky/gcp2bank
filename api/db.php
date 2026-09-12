<?php
function get_pdo() {
    $config = require __DIR__ . '/../db_config.php';
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // the server's session time_zone defaults to SYSTEM (not UTC), which silently
    // shifts UNIX_TIMESTAMP()/NOW()/etc. — every DATETIME in this schema is UTC,
    // so force the session to match or those functions misinterpret the data
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
