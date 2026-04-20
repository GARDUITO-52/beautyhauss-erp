<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['bh_user'])) { header('Location: /dashboard'); exit; }

$token   = $_GET['token'] ?? '';
$error   = '';
$success = false;
$valid   = false;
$row     = null;

if ($token) {
    $st = $pdo->prepare("SELECT t.id, t.user_id, t.expires_at, t.used_at, u.name
                         FROM password_reset_tokens t
                         JOIN users u ON t.user_id = u.id
                         WHERE t.token = ?");
    $st->execute([$token]);
    $row = $st->fetch();
    if ($row && !$row['used_at'] && strtotime($row['expires_at']) > time()) {
        $valid = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    csrfGuard();
    $pass    = $_POST['password']         ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (strlen($pass) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($pass !== $confirm) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $row['user_id']]);
        $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?")->execute([$row['id']]);
        logActivity($pdo, 'auth', 'password_reset_complete', $row['user_id'], $row['name']);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nueva contraseña — beautyhauss ERP</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #1a1a1a; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .card { background: #2a2a2a; border-radius: 16px; padding: 2.5rem; width: 100%; max-width: 380px; border: none; }
    .brand { font-size: 1.5rem; font-weight: 800; color: #d4537e; margin-bottom: .25rem; }
    .form-control:focus { border-color: #d4537e; box-shadow: 0 0 0 .2rem rgba(212,83,126,.25); }
  </style>
</head>
<body>
<div class="card">
  <div class="brand">💄 beautyhauss</div>
  <div class="text-white-50 small mb-4">Nueva contraseña</div>

  <?php if ($success): ?>
    <div class="alert alert-success">Contraseña actualizada. Ya puedes iniciar sesión.</div>
    <a href="/login" class="btn w-100 fw-bold mt-2" style="background:#d4537e;color:#fff">Ir al login</a>

  <?php elseif (!$valid): ?>
    <div class="alert alert-danger">El enlace es inválido o ha expirado.</div>
    <a href="/forgot_password" class="btn btn-outline-secondary w-100 mt-2">Solicitar nuevo enlace</a>

  <?php else: ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif ?>
    <p class="text-white-50 small mb-3">Hola <strong class="text-white"><?= htmlspecialchars($row['name']) ?></strong>, ingresa tu nueva contraseña.</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="mb-3">
        <label class="form-label text-white-50">Nueva contraseña</label>
        <input type="password" name="password" class="form-control bg-dark text-white border-secondary" required minlength="6" autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label text-white-50">Confirmar contraseña</label>
        <input type="password" name="password_confirm" class="form-control bg-dark text-white border-secondary" required minlength="6">
      </div>
      <button type="submit" class="btn w-100 fw-bold" style="background:#d4537e;color:#fff">Cambiar contraseña</button>
    </form>
    <a href="/login" class="d-block text-center mt-3 text-white-50 small">Volver al login</a>
  <?php endif ?>
</div>
</body>
</html>
