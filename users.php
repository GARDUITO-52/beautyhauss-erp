<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$page_title  = 'Usuarios — beautyhauss ERP';
$current_page = 'users';
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $name     = trim($_POST['name'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['admin','staff']) ? $_POST['role'] : 'staff';
        $password = $_POST['password'] ?? '';
        if ($username && $name && strlen($password) >= 6) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            try {
                $pdo->prepare("INSERT INTO users (username, name, password_hash, role) VALUES (?,?,?,?)")
                    ->execute([$username, $name, $hash, $role]);
                $success = "Usuario <strong>$username</strong> creado.";
            } catch (PDOException $e) {
                $error = 'El usuario ya existe.';
            }
        } else {
            $error = 'Completa todos los campos (mínimo 6 caracteres en contraseña).';
        }
    }

    if ($action === 'reset_password') {
        $uid      = (int)($_POST['user_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if ($uid && strlen($password) >= 6) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $uid]);
            $success = 'Contraseña actualizada.';
        } else {
            $error = 'Mínimo 6 caracteres.';
        }
    }

    if ($action === 'toggle') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $me  = current_user();
        if ($uid && $uid !== (int)$me['id']) {
            $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id=?")->execute([$uid]);
        }
    }
}

$users = $pdo->query("SELECT id, username, name, role, is_active, created_at FROM users ORDER BY role DESC, name ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 fw-bold">Usuarios</h1>
    <button class="btn btn-sm fw-bold" style="background:#d4537e;color:#fff" data-bs-toggle="modal" data-bs-target="#modalCreate">
      <i class="bi bi-person-plus me-1"></i>Nuevo usuario
    </button>
  </div>

  <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif ?>
  <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif ?>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead class="table-dark">
          <tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Estado</th><th>Creado</th><th></th></tr>
        </thead>
        <tbody>
        <?php $me = current_user(); foreach ($users as $u): ?>
          <tr class="<?= !$u['is_active'] ? 'text-muted' : '' ?>">
            <td class="fw-semibold"><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td><span class="badge <?= $u['role'] === 'admin' ? 'bg-danger' : 'bg-secondary' ?>"><?= $u['role'] ?></span></td>
            <td><span class="badge <?= $u['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $u['is_active'] ? 'Activo' : 'Inactivo' ?></span></td>
            <td class="text-muted small"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
            <td class="text-end">
              <?php if ((int)$u['id'] !== (int)$me['id']): ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary"><?= $u['is_active'] ? 'Desactivar' : 'Activar' ?></button>
              </form>
              <?php endif ?>
              <button class="btn btn-sm btn-outline-warning ms-1"
                data-bs-toggle="modal" data-bs-target="#modalReset"
                data-uid="<?= $u['id'] ?>" data-uname="<?= htmlspecialchars($u['username']) ?>">
                Reset pw
              </button>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Crear usuario -->
<div class="modal fade" id="modalCreate" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content bg-dark text-white">
      <input type="hidden" name="action" value="create">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">Nuevo usuario</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Usuario <small class="text-muted">(minúsculas, sin espacios)</small></label>
          <input type="text" name="username" class="form-control bg-dark text-white border-secondary" required pattern="[a-z0-9_]+">
        </div>
        <div class="mb-3">
          <label class="form-label">Nombre completo</label>
          <input type="text" name="name" class="form-control bg-dark text-white border-secondary" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Rol</label>
          <select name="role" class="form-select bg-dark text-white border-secondary">
            <option value="staff">Staff</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Contraseña inicial</label>
          <input type="text" name="password" class="form-control bg-dark text-white border-secondary" required minlength="6">
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn fw-bold" style="background:#d4537e;color:#fff">Crear</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Reset password -->
<div class="modal fade" id="modalReset" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content bg-dark text-white">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">Resetear contraseña — <span id="resetUserName"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Nueva contraseña</label>
        <input type="text" name="password" class="form-control bg-dark text-white border-secondary" required minlength="6">
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-warning fw-bold">Actualizar</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('modalReset').addEventListener('show.bs.modal', e => {
  document.getElementById('resetUserId').value   = e.relatedTarget.dataset.uid;
  document.getElementById('resetUserName').textContent = e.relatedTarget.dataset.uname;
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
