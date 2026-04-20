<?php
function csrfToken(): string {
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_time']) || (time() - $_SESSION['csrf_time']) > 7200) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_time']  = time();
    }
    return $_SESSION['csrf_token'];
}

function csrfValidate(): bool {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') return true;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
    if (!$token || !isset($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrfReject(): void {
    http_response_code(403);
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Token de seguridad inválido. Recarga la página.', 'csrf_error' => true]);
    } else {
        echo '<h3>Error de seguridad</h3><p>Token inválido. <a href="javascript:location.reload()">Recargar página</a></p>';
    }
    exit;
}

function csrfGuard(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (csrfValidate()) return;
    csrfReject();
}
