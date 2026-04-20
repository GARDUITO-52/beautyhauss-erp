<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';

date_default_timezone_set('America/Mexico_City');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('<h2>Error de conexión a la base de datos.</h2>');
}
