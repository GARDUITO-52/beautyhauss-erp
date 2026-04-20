<?php
if (session_status() === PHP_SESSION_ACTIVE) return;

$lifetime = 7 * 24 * 60 * 60;

ini_set('session.gc_maxlifetime', $lifetime);

session_set_cookie_params([
    'lifetime' => $lifetime,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();
