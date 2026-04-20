<?php $__user = current_user(); ?>
<nav class="sidebar d-flex flex-column flex-shrink-0 p-3 bg-dark text-white" style="width:240px;min-height:100vh">
  <a href="/dashboard" class="d-flex align-items-center mb-3 text-white text-decoration-none">
    <span class="fs-5 fw-bold">💄 beautyhauss</span>
  </a>
  <hr>
  <ul class="nav nav-pills flex-column mb-auto">
    <li class="nav-item">
      <a href="/dashboard" class="nav-link text-white <?= ($current_page ?? '') === 'dashboard' ? 'active' : '' ?>">
        <i class="bi bi-speedometer2 me-2"></i>Dashboard
      </a>
    </li>
    <li>
      <a href="/products" class="nav-link text-white <?= ($current_page ?? '') === 'products' ? 'active' : '' ?>">
        <i class="bi bi-box-seam me-2"></i>Productos
      </a>
    </li>
    <li>
      <a href="/shows" class="nav-link text-white <?= ($current_page ?? '') === 'shows' ? 'active' : '' ?>">
        <i class="bi bi-camera-video me-2"></i>Shows
      </a>
    </li>
    <li>
      <a href="/orders" class="nav-link text-white <?= ($current_page ?? '') === 'orders' ? 'active' : '' ?>">
        <i class="bi bi-bag-check me-2"></i>Órdenes
      </a>
    </li>
    <li>
      <a href="/expenses" class="nav-link text-white <?= ($current_page ?? '') === 'expenses' ? 'active' : '' ?>">
        <i class="bi bi-receipt me-2"></i>Gastos
      </a>
    </li>
    <hr class="text-white">
    <li>
      <a href="/suppliers" class="nav-link text-white <?= ($current_page ?? '') === 'suppliers' ? 'active' : '' ?>">
        <i class="bi bi-building me-2"></i>Proveedores
      </a>
    </li>
    <li>
      <a href="/purchase_batches" class="nav-link text-white <?= ($current_page ?? '') === 'batches' ? 'active' : '' ?>">
        <i class="bi bi-inboxes me-2"></i>Lotes de Compra
      </a>
    </li>
    <li>
      <a href="/hosts" class="nav-link text-white <?= ($current_page ?? '') === 'hosts' ? 'active' : '' ?>">
        <i class="bi bi-person-video3 me-2"></i>Hosts
      </a>
    </li>
    <hr class="text-white">
    <li>
      <a href="/calculator" class="nav-link text-white <?= ($current_page ?? '') === 'calculator' ? 'active' : '' ?>">
        <i class="bi bi-calculator me-2"></i>Calculadora
      </a>
    </li>
    <li>
      <a href="/reports" class="nav-link text-white <?= ($current_page ?? '') === 'reports' ? 'active' : '' ?>">
        <i class="bi bi-bar-chart me-2"></i>Reportes
      </a>
    </li>
    <?php if ($__user['role'] === 'admin'): ?>
    <hr class="text-white">
    <li>
      <a href="/users" class="nav-link text-white <?= ($current_page ?? '') === 'users' ? 'active' : '' ?>">
        <i class="bi bi-people me-2"></i>Usuarios
      </a>
    </li>
    <li>
      <a href="/config_system" class="nav-link text-white <?= ($current_page ?? '') === 'config' ? 'active' : '' ?>">
        <i class="bi bi-gear me-2"></i>Configuración
      </a>
    </li>
    <?php endif ?>
  </ul>
  <hr>
  <div class="d-flex align-items-center gap-2 mb-2">
    <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:.8rem;font-weight:700;flex-shrink:0">
      <?= strtoupper(substr($__user['name'] ?? '?', 0, 1)) ?>
    </div>
    <div class="overflow-hidden">
      <div class="text-white small fw-semibold text-truncate"><?= htmlspecialchars($__user['name'] ?? '') ?></div>
      <div class="text-white-50" style="font-size:.7rem"><?= $__user['role'] === 'admin' ? 'Admin' : 'Staff' ?></div>
    </div>
  </div>
  <a href="/logout" class="nav-link text-white-50"><i class="bi bi-box-arrow-left me-2"></i>Cerrar sesión</a>
</nav>
