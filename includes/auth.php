<?php
function require_login(): void {
    if (empty($_SESSION['bh_user'])) {
        header('Location: /login');
        exit;
    }
}

function require_admin(): void {
    require_login();
    if ($_SESSION['bh_user']['role'] !== 'admin') {
        http_response_code(403);
        die('<h2>Acceso restringido.</h2>');
    }
}

function current_user(): array {
    return $_SESSION['bh_user'] ?? [];
}

function attempt_login(PDO $pdo, string $username, string $password): bool {
    $stmt = $pdo->prepare("SELECT id, name, username, password_hash, role FROM users WHERE username = ? AND is_active = 1");
    $stmt->execute([strtolower(trim($username))]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) return false;
    session_regenerate_id(true);
    $_SESSION['bh_user'] = [
        'id'       => $user['id'],
        'username' => $user['username'],
        'name'     => $user['name'],
        'role'     => $user['role'],
    ];
    return true;
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: /login');
    exit;
}
