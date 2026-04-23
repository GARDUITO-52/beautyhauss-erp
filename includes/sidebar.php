<?php $__user = current_user(); $__isAdmin = $__user['role'] === 'admin'; ?>
<nav class="sidebar d-flex flex-column flex-shrink-0 p-3 bg-dark text-white" style="width:240px;min-height:100vh">
  <a href="/dashboard" class="d-flex align-items-center mb-3 text-white text-decoration-none">
    <span class="fs-5 fw-bold">💄 beautyhauss</span>
  </a>

  <!-- Dual clock -->
  <div class="rounded p-2 mb-3" style="background:rgba(255,255,255,.07);font-size:.72rem">
    <div class="d-flex justify-content-between align-items-center mb-1">
      <span class="text-white-50">🌴 Miami</span>
      <span class="fw-bold text-white font-monospace" id="clk-miami">--:-- --</span>
    </div>
    <div class="d-flex justify-content-between align-items-center">
      <span class="text-white-50">🌮 CDMX</span>
      <span class="fw-bold text-white-75 font-monospace" id="clk-cdmx">--:-- --</span>
    </div>
  </div>
  <script>
  (function() {
    var miamiTz = 'America/New_York';
    var cdmxTz  = 'America/Mexico_City';
    var fmtOpts = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, timeZone: '' };
    function tick() {
      var now = new Date();
      var mo = Object.assign({}, fmtOpts, { timeZone: miamiTz });
      var co = Object.assign({}, fmtOpts, { timeZone: cdmxTz  });
      document.getElementById('clk-miami').textContent = now.toLocaleTimeString('en-US', mo);
      document.getElementById('clk-cdmx').textContent  = now.toLocaleTimeString('en-US', co);
    }
    tick();
    setInterval(tick, 1000);
  })();
  </script>

  <hr>
  <ul class="nav nav-pills flex-column mb-auto">
    <li class="nav-item">
      <a href="/dashboard" class="nav-link text-white <?= ($current_page ?? '') === 'dashboard' ? 'active' : '' ?>">
        <i class="bi bi-speedometer2 me-2"></i>Dashboard
      </a>
    </li>

    <li class="nav-item mt-2"><span class="text-white-50 px-2" style="font-size:.65rem;text-transform:uppercase;letter-spacing:.08em">Operación</span></li>
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
      <a href="/products" class="nav-link text-white <?= ($current_page ?? '') === 'products' ? 'active' : '' ?>">
        <i class="bi bi-box-seam me-2"></i>Productos
      </a>
    </li>

    <?php if ($__isAdmin): ?>
    <li class="nav-item mt-2"><span class="text-white-50 px-2" style="font-size:.65rem;text-transform:uppercase;letter-spacing:.08em">Compras</span></li>
    <li>
      <a href="/purchase_batches" class="nav-link text-white <?= ($current_page ?? '') === 'batches' ? 'active' : '' ?>">
        <i class="bi bi-inboxes me-2"></i>Lotes de Compra
      </a>
    </li>
    <li>
      <a href="/suppliers" class="nav-link text-white <?= ($current_page ?? '') === 'suppliers' ? 'active' : '' ?>">
        <i class="bi bi-building me-2"></i>Proveedores
      </a>
    </li>

    <li class="nav-item mt-2"><span class="text-white-50 px-2" style="font-size:.65rem;text-transform:uppercase;letter-spacing:.08em">Equipo</span></li>
    <li>
      <a href="/hosts" class="nav-link text-white <?= ($current_page ?? '') === 'hosts' ? 'active' : '' ?>">
        <i class="bi bi-person-video3 me-2"></i>Hosts
      </a>
    </li>
    <li>
      <a href="/expenses" class="nav-link text-white <?= ($current_page ?? '') === 'expenses' ? 'active' : '' ?>">
        <i class="bi bi-receipt me-2"></i>Gastos
      </a>
    </li>

    <li class="nav-item mt-2"><span class="text-white-50 px-2" style="font-size:.65rem;text-transform:uppercase;letter-spacing:.08em">Análisis</span></li>
    <li>
      <a href="/reports" class="nav-link text-white <?= ($current_page ?? '') === 'reports' ? 'active' : '' ?>">
        <i class="bi bi-bar-chart me-2"></i>Reportes
      </a>
    </li>

    <li class="nav-item mt-2"><span class="text-white-50 px-2" style="font-size:.65rem;text-transform:uppercase;letter-spacing:.08em">Sistema</span></li>
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
      <div class="text-white-50" style="font-size:.7rem"><?= $__isAdmin ? 'Admin' : 'Staff' ?></div>
    </div>
  </div>
  <a href="/logout" class="nav-link text-white-50"><i class="bi bi-box-arrow-left me-2"></i>Cerrar sesión</a>
</nav>
