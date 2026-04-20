<?php
function require_login(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['bh_logged_in'])) {
        header('Location: /login');
        exit;
    }
}

function attempt_login(PDO $pdo, string $password): bool {
    $row = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'admin_password'")->fetch();
    if (!$row) return false;
    if (password_verify($password, $row['config_value'])) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['bh_logged_in'] = true;
        return true;
    }
    return false;
}

function logout(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_destroy();
    header('Location: /login');
    exit;
}
