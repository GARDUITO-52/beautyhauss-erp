<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

// ── AJAX handlers ──────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'list') {
        $search = trim($_GET['search'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 50;
        $offset = ($page - 1) * $limit;

        $where  = $search ? "WHERE name LIKE ? OR contact_name LIKE ? OR email LIKE ?" : '';
        $params = $search ? ["%$search%", "%$search%", "%$search%"] : [];

        $total = $pdo->prepare("SELECT COUNT(*) FROM suppliers $where");
        $total->execute($params);
        $total = (int)$total->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM suppliers $where ORDER BY name ASC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);

        jsonOk(['suppliers' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, ceil($total / $limit))]);
    }

    if ($action === 'get') {
        $id   = (int)($_GET['id'] ?? 0);
        $row  = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $row->execute([$id]);
        $row  = $row->fetch();
        if (!$row) jsonErr('Proveedor no encontrado', 404);
        jsonOk($row);
    }

    if ($action === 'create') {
        csrfGuard();
        $d    = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($d['name'] ?? '');
        if (!$name) jsonErr('El nombre es requerido.');
        $pdo->prepare("INSERT INTO suppliers (name, contact_name, email, country, notes) VALUES (?,?,?,?,?)")
            ->execute([$name, trim($d['contact_name'] ?? ''), trim($d['email'] ?? ''), trim($d['country'] ?? 'USA'), trim($d['notes'] ?? '')]);
        $id = $pdo->lastInsertId();
        logActivity($pdo, 'suppliers', 'create', $id, $name);
        jsonOk(['id' => $id]);
    }

    if ($action === 'update') {
        csrfGuard();
        $d    = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($d['id'] ?? 0);
        $name = trim($d['name'] ?? '');
        if (!$id || !$name) jsonErr('Datos inválidos.');
        $pdo->prepare("UPDATE suppliers SET name=?, contact_name=?, email=?, country=?, notes=? WHERE id=?")
            ->execute([$name, trim($d['contact_name'] ?? ''), trim($d['email'] ?? ''), trim($d['country'] ?? 'USA'), trim($d['notes'] ?? ''), $id]);
        logActivity($pdo, 'suppliers', 'update', $id, $name);
        jsonOk();
    }

    if ($action === 'delete') {
        csrfGuard();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonErr('ID inválido.');
        $used = $pdo->prepare("SELECT COUNT(*) FROM purchase_batches WHERE supplier_id = ?");
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) jsonErr('No se puede eliminar: tiene lotes de compra asociados.');
        $row = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
        $row->execute([$id]);
        $name = $row->fetchColumn();
        $pdo->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
        logActivity($pdo, 'suppliers', 'delete', $id, $name);
        jsonOk();
    }

    jsonErr('Acción no reconocida.', 400);
}

// ── Page render ────────────────────────────────────────────────────────────
$page_title   = 'Proveedores — beautyhauss ERP';
$current_page = 'suppliers';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 fw-bold">Proveedores</h1>
    <button class="btn btn-sm fw-bold" style="background:#d4537e;color:#fff" onclick="openCreate()">
      <i class="bi bi-plus-lg me-1"></i>Nuevo proveedor
    </button>
  </div>

  <!-- Search -->
  <div class="card shadow-sm mb-3">
    <div class="card-body py-2">
      <input type="search" id="searchInput" class="form-control" placeholder="Buscar por nombre, contacto o email…">
    </div>
  </div>

  <!-- Table -->
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover mb-0" id="suppliersTable">
        <thead class="table-dark">
          <tr><th>Nombre</th><th>Contacto</th><th>Email</th><th>País</th><th>Notas</th><th></th></tr>
        </thead>
        <tbody id="suppliersBody">
          <tr><td colspan="6" class="text-center text-muted py-4">Cargando…</td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center" id="pagination" style="display:none!important"></div>
  </div>
</div>

<!-- Modal: Create / Edit -->
<div class="modal fade" id="modalForm" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title" id="modalTitle">Nuevo proveedor</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="fId">
        <div class="mb-3">
          <label class="form-label">Nombre <span class="text-danger">*</span></label>
          <input type="text" id="fName" class="form-control bg-dark text-white border-secondary" required>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Contacto</label>
            <input type="text" id="fContact" class="form-control bg-dark text-white border-secondary">
          </div>
          <div class="col-md-6">
            <label class="form-label">País</label>
            <input type="text" id="fCountry" class="form-control bg-dark text-white border-secondary" value="USA">
          </div>
        </div>
        <div class="mb-3 mt-3">
          <label class="form-label">Email</label>
          <input type="email" id="fEmail" class="form-control bg-dark text-white border-secondary">
        </div>
        <div class="mb-3">
          <label class="form-label">Notas</label>
          <textarea id="fNotes" class="form-control bg-dark text-white border-secondary" rows="2"></textarea>
        </div>
        <div id="formError" class="alert alert-danger py-2 d-none"></div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn fw-bold" style="background:#d4537e;color:#fff" onclick="saveSupplier()">Guardar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Delete confirm -->
<div class="modal fade" id="modalDelete" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content bg-dark text-white">
      <div class="modal-body text-center py-4">
        <i class="bi bi-exclamation-triangle text-warning fs-2 mb-3 d-block"></i>
        <p>¿Eliminar <strong id="deleteName"></strong>?</p>
        <p class="text-muted small">Esta acción no se puede deshacer.</p>
      </div>
      <div class="modal-footer border-secondary justify-content-center">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger fw-bold" onclick="confirmDelete()">Eliminar</button>
      </div>
    </div>
  </div>
</div>

<script>
var _deleteId = null;
var _modal    = null;
var _modalDel = null;

document.addEventListener('DOMContentLoaded', function() {
    _modal    = new bootstrap.Modal(document.getElementById('modalForm'));
    _modalDel = new bootstrap.Modal(document.getElementById('modalDelete'));
    loadSuppliers();
    var t; document.getElementById('searchInput').addEventListener('input', function() { clearTimeout(t); t = setTimeout(loadSuppliers, 300); });
});

function loadSuppliers(page) {
    page = page || 1;
    var search = document.getElementById('searchInput').value;
    apiFetch('?action=list&page=' + page + '&search=' + encodeURIComponent(search))
        .then(function(d) {
            if (!d.ok) { toast(d.error, 'error'); return; }
            renderTable(d.data.suppliers);
            renderPagination(d.data.page, d.data.pages);
        });
}

function renderTable(rows) {
    var tb = document.getElementById('suppliersBody');
    if (!rows.length) { tb.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Sin proveedores registrados.</td></tr>'; return; }
    tb.innerHTML = rows.map(function(r) {
        return '<tr>'
            + '<td class="fw-semibold">' + esc(r.name) + '</td>'
            + '<td>' + esc(r.contact_name || '—') + '</td>'
            + '<td>' + (r.email ? '<a href="mailto:' + esc(r.email) + '" class="text-decoration-none">' + esc(r.email) + '</a>' : '—') + '</td>'
            + '<td>' + esc(r.country || '—') + '</td>'
            + '<td class="text-muted small">' + esc(r.notes || '—') + '</td>'
            + '<td class="text-end">'
            +   '<button class="btn btn-sm btn-outline-secondary me-1" onclick="openEdit(' + r.id + ')"><i class="bi bi-pencil"></i></button>'
            +   '<button class="btn btn-sm btn-outline-danger" onclick="openDelete(' + r.id + ',\'' + esc(r.name) + '\')"><i class="bi bi-trash"></i></button>'
            + '</td></tr>';
    }).join('');
}

function renderPagination(page, pages) {
    var el = document.getElementById('pagination');
    if (pages <= 1) { el.style.display = 'none'; return; }
    el.style.display = '';
    el.innerHTML = '<span class="text-muted small">Página ' + page + ' de ' + pages + '</span>'
        + '<div>'
        + (page > 1 ? '<button class="btn btn-sm btn-outline-secondary me-1" onclick="loadSuppliers(' + (page-1) + ')">‹ Anterior</button>' : '')
        + (page < pages ? '<button class="btn btn-sm btn-outline-secondary" onclick="loadSuppliers(' + (page+1) + ')">Siguiente ›</button>' : '')
        + '</div>';
}

function openCreate() {
    document.getElementById('modalTitle').textContent = 'Nuevo proveedor';
    document.getElementById('fId').value      = '';
    document.getElementById('fName').value    = '';
    document.getElementById('fContact').value = '';
    document.getElementById('fCountry').value = 'USA';
    document.getElementById('fEmail').value   = '';
    document.getElementById('fNotes').value   = '';
    document.getElementById('formError').classList.add('d-none');
    _modal.show();
}

function openEdit(id) {
    apiFetch('?action=get&id=' + id).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        var r = d.data;
        document.getElementById('modalTitle').textContent = 'Editar proveedor';
        document.getElementById('fId').value      = r.id;
        document.getElementById('fName').value    = r.name;
        document.getElementById('fContact').value = r.contact_name || '';
        document.getElementById('fCountry').value = r.country || 'USA';
        document.getElementById('fEmail').value   = r.email || '';
        document.getElementById('fNotes').value   = r.notes || '';
        document.getElementById('formError').classList.add('d-none');
        _modal.show();
    });
}

function saveSupplier() {
    var id   = document.getElementById('fId').value;
    var name = document.getElementById('fName').value.trim();
    if (!name) { showFormError('El nombre es requerido.'); return; }

    var payload = {
        id:           id ? parseInt(id) : undefined,
        name:         name,
        contact_name: document.getElementById('fContact').value.trim(),
        country:      document.getElementById('fCountry').value.trim(),
        email:        document.getElementById('fEmail').value.trim(),
        notes:        document.getElementById('fNotes').value.trim()
    };

    var action = id ? 'update' : 'create';
    apiFetch('?action=' + action, { body: payload }).then(function(d) {
        if (!d.ok) { showFormError(d.error); return; }
        _modal.hide();
        toast(id ? 'Proveedor actualizado.' : 'Proveedor creado.');
        loadSuppliers();
    });
}

function openDelete(id, name) {
    _deleteId = id;
    document.getElementById('deleteName').textContent = name;
    _modalDel.show();
}

function confirmDelete() {
    apiFetch('?action=delete&id=' + _deleteId, { method: 'POST' }).then(function(d) {
        _modalDel.hide();
        if (!d.ok) { toast(d.error, 'error'); return; }
        toast('Proveedor eliminado.');
        loadSuppliers();
    });
}

function showFormError(msg) {
    var el = document.getElementById('formError');
    el.textContent = msg;
    el.classList.remove('d-none');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
