<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['bh_user'])) { header('Location: /dashboard'); exit; }

$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfGuard();
    $email = trim($_POST['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresa un correo electrónico válido.';
    } else {
        $success = true; // Always show success to prevent email enumeration

        $user = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? AND is_active = 1");
        $user->execute([strtolower($email)]);
        $user = $user->fetch();

        if ($user) {
            $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$user['id']]);

            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)")
                ->execute([$user['id'], $token, $expires]);

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'] ?? 'bh-erp.erptiendatopmx.com';
            $resetUrl = "{$protocol}://{$host}/reset_password?token={$token}";

            $subject = 'Recuperar contraseña — beautyhauss ERP';
            $body    = "Hola {$user['name']},\n\n"
                     . "Recibimos una solicitud para restablecer tu contraseña.\n\n"
                     . "Haz clic en el siguiente enlace (válido por 1 hora):\n"
                     . "{$resetUrl}\n\n"
                     . "Si no solicitaste esto, ignora este mensaje.\n\n"
                     . "— beautyhauss ERP";

            $headers = "From: noreply@erptiendatopmx.com\r\n"
                     . "Reply-To: noreply@erptiendatopmx.com\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n";

            @mail($user['email'], $subject, $body, $headers);

            logActivity($pdo, 'auth', 'password_reset_request', $user['id'], $user['email']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Recuperar contraseña — beautyhauss ERP</title>
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
  <div class="text-white-50 small mb-4">Recuperar contraseña</div>

  <?php if ($success): ?>
    <div class="alert alert-success">Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.</div>
    <a href="/login" class="btn btn-outline-secondary w-100 mt-2">Volver al login</a>
  <?php else: ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif ?>
    <p class="text-white-50 small mb-3">Ingresa tu correo y te enviaremos un enlace de recuperación.</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="mb-3">
        <label class="form-label text-white-50">Correo electrónico</label>
        <input type="email" name="email" class="form-control bg-dark text-white border-secondary" autofocus required>
      </div>
      <button type="submit" class="btn w-100 fw-bold" style="background:#d4537e;color:#fff">Enviar enlace</button>
    </form>
    <a href="/login" class="d-block text-center mt-3 text-white-50 small">Volver al login</a>
  <?php endif ?>
</div>
</body>
</html>
