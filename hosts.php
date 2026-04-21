<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

// ── AJAX ───────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'list') {
        $rows = $pdo->query("SELECT * FROM hosts ORDER BY is_active DESC, name ASC")->fetchAll();
        jsonOk($rows);
    }

    if ($action === 'get') {
        $id  = (int)($_GET['id'] ?? 0);
        $row = $pdo->prepare("SELECT * FROM hosts WHERE id=?");
        $row->execute([$id]); $row = $row->fetch();
        if (!$row) jsonErr('No encontrado.', 404);
        jsonOk($row);
    }

    if ($action === 'create') {
        csrfGuard();
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($d['name'] ?? '');
        if (!$name) jsonErr('Nombre requerido.');
        $pdo->prepare("INSERT INTO hosts (name, hourly_rate_usd, commission_pct, contact, notes, is_active) VALUES (?,?,?,?,?,1)")
            ->execute([$name, $d['hourly_rate_usd'] ?? 50.00, $d['commission_pct'] ?? 0.00, trim($d['contact'] ?? ''), trim($d['notes'] ?? '')]);
        $id = $pdo->lastInsertId();
        logActivity($pdo, 'hosts', 'create', $id, $name);
        jsonOk(['id' => $id]);
    }

    if ($action === 'update') {
        csrfGuard();
        $d  = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($d['id'] ?? 0);
        $name = trim($d['name'] ?? '');
        if (!$id || !$name) jsonErr('Datos inválidos.');
        $pdo->prepare("UPDATE hosts SET name=?, hourly_rate_usd=?, commission_pct=?, contact=?, notes=?, is_active=? WHERE id=?")
            ->execute([$name, $d['hourly_rate_usd'] ?? 50.00, $d['commission_pct'] ?? 0.00, trim($d['contact'] ?? ''), trim($d['notes'] ?? ''), (int)($d['is_active'] ?? 1), $id]);
        logActivity($pdo, 'hosts', 'update', $id, $name);
        jsonOk();
    }

    if ($action === 'toggle') {
        csrfGuard();
        $d  = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) jsonErr('ID requerido.');
        $pdo->prepare("UPDATE hosts SET is_active = NOT is_active WHERE id=?")->execute([$id]);
        $active = (int)$pdo->query("SELECT is_active FROM hosts WHERE id=$id")->fetchColumn();
        logActivity($pdo, 'hosts', 'toggle', $id, $active ? 'activado' : 'desactivado');
        jsonOk(['is_active' => $active]);
    }

    jsonErr('Acción no reconocida.', 400);
}

// ── Page ───────────────────────────────────────────────────────────────────
$page_title   = 'Hosts — beautyhauss ERP';
$current_page = 'hosts';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 fw-bold">Hosts / Streamers</h1>
    <button class="btn btn-sm fw-bold" style="background:#d4537e;color:#fff" onclick="openCreate()">
      <i class="bi bi-plus-lg me-1"></i>Nueva host
    </button>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover mb-0" id="hostsTable">
        <thead class="table-dark">
          <tr>
            <th>Nombre</th>
            <th class="text-end">Tarifa/hr</th>
            <th class="text-end">Comisión %</th>
            <th>Contacto</th>
            <th>Notas</th>
            <th class="text-center">Estado</th>
            <th class="text-center">Acciones</th>
          </tr>
        </thead>
        <tbody><tr><td colspan="7" class="text-center text-muted py-4">Cargando…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Create/Edit -->
<div class="modal fade" id="modalHost" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title" id="hostModalTitle">Nueva host</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="hId">
        <div class="mb-3">
          <label class="form-label">Nombre <span class="text-danger">*</span></label>
          <input type="text" id="hName" class="form-control bg-dark text-white border-secondary" placeholder="ej. Rosy">
        </div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Tarifa USD/hora</label>
            <div class="input-group">
              <span class="input-group-text bg-dark text-white border-secondary">$</span>
              <input type="number" id="hRate" class="form-control bg-dark text-white border-secondary" step="5" min="0" value="50">
            </div>
          </div>
          <div class="col-6">
            <label class="form-label">Comisión %</label>
            <div class="input-group">
              <input type="number" id="hComm" class="form-control bg-dark text-white border-secondary" step="0.5" min="0" max="20" value="0">
              <span class="input-group-text bg-dark text-white border-secondary">%</span>
            </div>
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label">Contacto <span class="text-muted small">(teléfono o @)</span></label>
          <input type="text" id="hContact" class="form-control bg-dark text-white border-secondary" placeholder="ej. +52 55 1234 5678">
        </div>
        <div class="mt-3">
          <label class="form-label">Notas</label>
          <textarea id="hNotes" class="form-control bg-dark text-white border-secondary" rows="2"></textarea>
        </div>
        <div id="hostError" class="alert alert-danger py-2 mt-3 d-none"></div>
      </div>
      <div class="modal-footer border-secondary">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn fw-bold" style="background:#d4537e;color:#fff" onclick="saveHost()">Guardar</button>
      </div>
    </div>
  </div>
</div>

<script>
var _hostModal = null;

document.addEventListener('DOMContentLoaded', function() {
    _hostModal = new bootstrap.Modal(document.getElementById('modalHost'));
    loadHosts();
});

function loadHosts() {
    apiFetch('?action=list').then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        renderHosts(d.data);
    });
}

function renderHosts(hosts) {
    var tbody = document.querySelector('#hostsTable tbody');
    if (!hosts.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Sin hosts registradas. Agrega la primera.</td></tr>';
        return;
    }
    tbody.innerHTML = hosts.map(function(h) {
        var active = parseInt(h.is_active);
        return '<tr class="' + (active ? '' : 'opacity-50') + '">'
            + '<td class="fw-semibold">' + esc(h.name) + '</td>'
            + '<td class="text-end">' + fmt2(h.hourly_rate_usd) + '/hr</td>'
            + '<td class="text-end">' + fmtPct(h.commission_pct) + '</td>'
            + '<td class="text-muted small">' + esc(h.contact || '—') + '</td>'
            + '<td class="text-muted small">' + esc(h.notes || '—') + '</td>'
            + '<td class="text-center">'
            + '<span class="badge ' + (active ? 'bg-success' : 'bg-secondary') + '" style="cursor:pointer" onclick="toggleHost(' + h.id + ')">'
            + (active ? 'Activa' : 'Inactiva') + '</span>'
            + '</td>'
            + '<td class="text-center">'
            + '<button class="btn btn-sm btn-outline-secondary" onclick="openEdit(' + h.id + ')"><i class="bi bi-pencil"></i></button>'
            + '</td>'
            + '</tr>';
    }).join('');
}

function openCreate() {
    document.getElementById('hostModalTitle').textContent = 'Nueva host';
    document.getElementById('hId').value      = '';
    document.getElementById('hName').value    = '';
    document.getElementById('hRate').value    = '50';
    document.getElementById('hComm').value    = '0';
    document.getElementById('hContact').value = '';
    document.getElementById('hNotes').value   = '';
    document.getElementById('hostError').classList.add('d-none');
    _hostModal.show();
}

function openEdit(id) {
    apiFetch('?action=get&id=' + id).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        var r = d.data;
        document.getElementById('hostModalTitle').textContent = 'Editar host';
        document.getElementById('hId').value      = r.id;
        document.getElementById('hName').value    = r.name;
        document.getElementById('hRate').value    = r.hourly_rate_usd;
        document.getElementById('hComm').value    = r.commission_pct;
        document.getElementById('hContact').value = r.contact || '';
        document.getElementById('hNotes').value   = r.notes || '';
        document.getElementById('hostError').classList.add('d-none');
        _hostModal.show();
    });
}

function saveHost() {
    var id = document.getElementById('hId').value;
    var name = document.getElementById('hName').value.trim();
    if (!name) {
        document.getElementById('hostError').textContent = 'Nombre requerido.';
        document.getElementById('hostError').classList.remove('d-none'); return;
    }
    var payload = {
        id:              id ? parseInt(id) : undefined,
        name:            name,
        hourly_rate_usd: parseFloat(document.getElementById('hRate').value || 50),
        commission_pct:  parseFloat(document.getElementById('hComm').value || 0),
        contact:         document.getElementById('hContact').value.trim(),
        notes:           document.getElementById('hNotes').value.trim(),
        is_active:       1,
    };
    apiFetch('?action=' + (id ? 'update' : 'create'), { body: payload }).then(function(d) {
        if (!d.ok) {
            document.getElementById('hostError').textContent = d.error;
            document.getElementById('hostError').classList.remove('d-none'); return;
        }
        _hostModal.hide();
        toast(id ? 'Host actualizada.' : 'Host creada.');
        loadHosts();
    });
}

function toggleHost(id) {
    apiFetch('?action=toggle', { body: { id: id } }).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        toast(d.data.is_active ? 'Host activada.' : 'Host desactivada.');
        loadHosts();
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
