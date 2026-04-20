<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['bh_logged_in'])) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (attempt_login($pdo, $password)) {
        header('Location: /dashboard.php');
        exit;
    }
    $error = 'Contraseña incorrecta.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>beautyhauss ERP — Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #1a1a1a; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .login-card { background: #2a2a2a; border-radius: 16px; padding: 2.5rem; width: 100%; max-width: 380px; }
    .brand { font-size: 1.5rem; font-weight: 800; color: #d4537e; margin-bottom: .25rem; }
    .sub { color: #888; font-size: .875rem; margin-bottom: 2rem; }
  </style>
</head>
<body>
<div class="login-card">
  <div class="brand">💄 beautyhauss</div>
  <div class="sub">ERP — Acceso del equipo</div>
  <?php if ($error): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
  <?php endif ?>
  <form method="POST">
    <div class="mb-3">
      <label class="form-label text-white-50">Contraseña</label>
      <input type="password" name="password" class="form-control bg-dark text-white border-secondary" autofocus required>
    </div>
    <button type="submit" class="btn w-100" style="background:#d4537e;color:#fff;font-weight:700">Entrar</button>
  </form>
</div>
</body>
</html>
