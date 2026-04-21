<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$is_admin = current_user()['role'] === 'admin';

// ── CSV DOWNLOADS (bypass JSON header) ────────────────────────────────────
if (isset($_GET['action']) && in_array($_GET['action'], ['export_pre_show', 'download_template'])) {
    require_admin();
    $show_id = (int)($_GET['show_id'] ?? 0);
    if (!$show_id) { http_response_code(400); echo 'show_id requerido.'; exit; }

    if ($_GET['action'] === 'export_pre_show') {
        $rows = $pdo->prepare("
            SELECT sp.whatnot_slot, p.sku_internal, p.brand, p.description,
                   p.color, p.size, sp.qty_listed, p.rescue_price_usd
            FROM show_products sp JOIN products p ON p.id = sp.product_id
            WHERE sp.show_id = ? ORDER BY sp.whatnot_slot, sp.id ASC");
        $rows->execute([$show_id]);
        $items = $rows->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="show-' . $show_id . '-lineup.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['slot','sku_internal','brand','description','color','size','qty_listed','rescue_price_usd']);
        foreach ($items as $r) {
            fputcsv($out, [$r['whatnot_slot'] ?? '', $r['sku_internal'] ?? '', $r['brand'], $r['description'],
                           $r['color'] ?? '', $r['size'] ?? '', $r['qty_listed'], number_format((float)$r['rescue_price_usd'],2,'.','')]);
        }
        fclose($out); exit;
    }

    if ($_GET['action'] === 'download_template') {
        $slots = $pdo->prepare("SELECT whatnot_slot FROM show_products WHERE show_id=? AND whatnot_slot IS NOT NULL ORDER BY whatnot_slot ASC");
        $slots->execute([$show_id]);
        $slots = $slots->fetchAll(PDO::FETCH_COLUMN);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="show-' . $show_id . '-post-show-template.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['slot','qty_sold','sale_price_usd']);
        if ($slots) {
            foreach ($slots as $s) fputcsv($out, [$s, '', '']);
        } else {
            fputcsv($out, ['001','','']); fputcsv($out, ['002','','']);
        }
        fclose($out); exit;
    }
}

// ── AJAX ───────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'list') {
        $status = $_GET['status'] ?? '';
        $where  = $status ? "WHERE s.status = ?" : "WHERE s.status != 'CANCELLED'";
        $params = $status ? [$status] : [];
        $rows   = $pdo->prepare("
            SELECT s.id, s.title, s.scheduled_at, s.estimated_duration_hrs, s.status,
                   h.name AS host_name
            FROM shows s LEFT JOIN hosts h ON h.id = s.host_id
            $where ORDER BY s.scheduled_at DESC LIMIT 100");
        $rows->execute($params);
        jsonOk($rows->fetchAll());
    }

    if ($action === 'lineup') {
        $show_id = (int)($_GET['show_id'] ?? 0);
        if (!$show_id) jsonErr('show_id requerido.');
        $show = $pdo->prepare("SELECT s.id, s.title, s.scheduled_at, s.status, h.name AS host FROM shows s LEFT JOIN hosts h ON h.id=s.host_id WHERE s.id=?");
        $show->execute([$show_id]); $show = $show->fetch();
        if (!$show) jsonErr('Show no encontrado.', 404);
        $items = $pdo->prepare("
            SELECT sp.id, sp.qty_listed, sp.starting_bid_usd, sp.whatnot_slot,
                   p.brand, p.description, p.upc, p.color, p.size, p.stock_qty
            FROM show_products sp JOIN products p ON p.id = sp.product_id
            WHERE sp.show_id = ? ORDER BY sp.whatnot_slot ASC, sp.id ASC");
        $items->execute([$show_id]);
        jsonOk(['show' => $show, 'items' => $items->fetchAll()]);
    }

    if ($action === 'update_slot') {
        require_admin(); csrfGuard();
        $d       = json_decode(file_get_contents('php://input'), true) ?? [];
        $sp_id   = (int)($d['id'] ?? 0);
        $show_id = (int)($d['show_id'] ?? 0);
        $slot    = trim($d['whatnot_slot'] ?? '');
        if (!$sp_id || !$show_id) jsonErr('Datos inválidos.');
        $pdo->prepare("UPDATE show_products SET whatnot_slot=? WHERE id=? AND show_id=?")
            ->execute([$slot ?: null, $sp_id, $show_id]);
        jsonOk();
    }

    if ($is_admin) {
        if ($action === 'get') {
            $id  = (int)($_GET['id'] ?? 0);
            $row = $pdo->prepare("SELECT * FROM shows WHERE id=?");
            $row->execute([$id]); $row = $row->fetch();
            if (!$row) jsonErr('No encontrado.', 404);
            jsonOk($row);
        }
        if ($action === 'create') {
            csrfGuard();
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $title = trim($d['title'] ?? '');
            if (!$title || empty($d['scheduled_at'])) jsonErr('Título y fecha son requeridos.');
            $pdo->prepare("INSERT INTO shows (title, scheduled_at, estimated_duration_hrs, host_id, status, notes) VALUES (?,?,?,?,?,?)")
                ->execute([$title, $d['scheduled_at'], $d['estimated_duration_hrs'] ?? 2, $d['host_id'] ?: null, 'SCHEDULED', trim($d['notes'] ?? '')]);
            $id = $pdo->lastInsertId();
            logActivity($pdo, 'shows', 'create', $id, $title);
            jsonOk(['id' => $id]);
        }
        if ($action === 'update') {
            csrfGuard();
            $d  = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = (int)($d['id'] ?? 0);
            $title = trim($d['title'] ?? '');
            if (!$id || !$title) jsonErr('Datos inválidos.');
            $pdo->prepare("UPDATE shows SET title=?, scheduled_at=?, estimated_duration_hrs=?, host_id=?, status=?, notes=? WHERE id=?")
                ->execute([$title, $d['scheduled_at'], $d['estimated_duration_hrs'] ?? 2, $d['host_id'] ?: null, $d['status'] ?? 'SCHEDULED', trim($d['notes'] ?? ''), $id]);
            logActivity($pdo, 'shows', 'update', $id, $title);
            jsonOk();
        }
        if ($action === 'delete') {
            csrfGuard();
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonErr('ID inválido.');
            $row = $pdo->prepare("SELECT title FROM shows WHERE id=?"); $row->execute([$id]);
            $title = $row->fetchColumn();
            $pdo->prepare("DELETE FROM shows WHERE id=?")->execute([$id]);
            logActivity($pdo, 'shows', 'delete', $id, $title);
            jsonOk();
        }
    }

    jsonErr('No autorizado.', 403);
}

// ── Page ───────────────────────────────────────────────────────────────────
$hosts        = $is_admin ? $pdo->query("SELECT id, name FROM hosts WHERE is_active=1 ORDER BY name")->fetchAll() : [];
$page_title   = 'Shows — beautyhauss ERP';
$current_page = 'shows';
$selected_show = (int)($_GET['id'] ?? 0);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 fw-bold">Shows</h1>
    <?php if ($is_admin): ?>
    <button class="btn btn-sm fw-bold" style="background:#d4537e;color:#fff" onclick="openCreate()">
      <i class="bi bi-plus-lg me-1"></i>Nuevo show
    </button>
    <?php endif ?>
  </div>

  <div class="row g-3">
    <!-- Lista de shows -->
    <div class="col-md-4">
      <div class="card shadow-sm">
        <div class="card-header d-flex gap-2">
          <select id="statusFilter" class="form-select form-select-sm">
            <option value="">Todos</option>
            <option value="SCHEDULED" selected>Programados</option>
            <option value="LIVE">En vivo</option>
            <option value="COMPLETED">Completados</option>
          </select>
        </div>
        <div class="list-group list-group-flush" id="showsList">
          <div class="list-group-item text-muted text-center py-3">Cargando…</div>
        </div>
      </div>
    </div>

    <!-- Lineup / detalle -->
    <div class="col-md-8">
      <div id="lineupPanel">
        <div class="card shadow-sm">
          <div class="card-body text-center text-muted py-5">
            <i class="bi bi-camera-video fs-1 d-block mb-2"></i>
            Selecciona un show para ver el lineup
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($is_admin): ?>
<!-- Modal: Create/Edit (admin only) -->
<div class="modal fade" id="modalForm" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title" id="modalTitle">Nuevo show</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="fId">
        <div class="mb-3">
          <label class="form-label">Título <span class="text-danger">*</span></label>
          <input type="text" id="fTitle" class="form-control bg-dark text-white border-secondary">
        </div>
        <div class="row g-3">
          <div class="col-md-7">
            <label class="form-label">Fecha y hora <span class="text-danger">*</span></label>
            <input type="datetime-local" id="fDate" class="form-control bg-dark text-white border-secondary">
          </div>
          <div class="col-md-5">
            <label class="form-label">Duración (hrs)</label>
            <input type="number" id="fDuration" class="form-control bg-dark text-white border-secondary" value="2" min="0.5" step="0.5">
          </div>
        </div>
        <div class="row g-3 mt-0">
          <div class="col-md-6">
            <label class="form-label">Host</label>
            <select id="fHost" class="form-select bg-dark text-white border-secondary">
              <option value="">— Sin asignar —</option>
              <?php foreach ($hosts as $h): ?>
              <option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['name']) ?></option>
              <?php endforeach ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Status</label>
            <select id="fStatus" class="form-select bg-dark text-white border-secondary">
              <option value="SCHEDULED">Programado</option>
              <option value="LIVE">En vivo</option>
              <option value="COMPLETED">Completado</option>
              <option value="CANCELLED">Cancelado</option>
            </select>
          </div>
        </div>
        <div class="mb-3 mt-3">
          <label class="form-label">Notas</label>
          <textarea id="fNotes" class="form-control bg-dark text-white border-secondary" rows="2"></textarea>
        </div>
        <div id="formError" class="alert alert-danger py-2 d-none"></div>
      </div>
      <div class="modal-footer border-secondary">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn fw-bold" style="background:#d4537e;color:#fff" onclick="saveShow()">Guardar</button>
      </div>
    </div>
  </div>
</div>
<?php endif ?>

<script>
var IS_ADMIN    = <?= $is_admin ? 'true' : 'false' ?>;
var _modal      = null;
var _activeShow = <?= $selected_show ?: 'null' ?>;

var STATUS_LABELS = { SCHEDULED:'Programado', LIVE:'En vivo', COMPLETED:'Completado', CANCELLED:'Cancelado' };
var STATUS_COLORS = { SCHEDULED:'secondary', LIVE:'danger', COMPLETED:'success', CANCELLED:'dark' };

document.addEventListener('DOMContentLoaded', function() {
    if (IS_ADMIN) _modal = new bootstrap.Modal(document.getElementById('modalForm'));
    loadShows();
    document.getElementById('statusFilter').addEventListener('change', loadShows);
    if (_activeShow) loadLineup(_activeShow);
});

function loadShows() {
    var status = document.getElementById('statusFilter').value;
    apiFetch('?action=list&status=' + encodeURIComponent(status)).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        renderShowsList(d.data);
    });
}

function renderShowsList(shows) {
    var el = document.getElementById('showsList');
    if (!shows.length) { el.innerHTML = '<div class="list-group-item text-muted text-center py-3">Sin shows.</div>'; return; }
    el.innerHTML = shows.map(function(s) {
        var active = s.id === _activeShow ? 'active' : '';
        return '<a href="#" class="list-group-item list-group-item-action ' + active + '" onclick="loadLineup(' + s.id + ');return false;">'
            + '<div class="d-flex justify-content-between">'
            + '<span class="fw-semibold">' + esc(s.title) + '</span>'
            + '<span class="badge bg-' + STATUS_COLORS[s.status] + '">' + STATUS_LABELS[s.status] + '</span>'
            + '</div>'
            + '<div class="text-muted small">' + formatDate(s.scheduled_at) + (s.host_name ? ' · ' + esc(s.host_name) : '') + '</div>'
            + '</a>';
    }).join('');
}

function loadLineup(showId) {
    _activeShow = showId;
    document.querySelectorAll('#showsList a').forEach(function(a) { a.classList.remove('active'); });
    var links = document.querySelectorAll('#showsList a');
    apiFetch('?action=lineup&show_id=' + showId).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        renderLineup(d.data.show, d.data.items);
        // re-highlight active
        loadShows();
    });
}

function renderLineup(show, items) {
    var adminEdit = IS_ADMIN
        ? ' <button class="btn btn-sm btn-outline-secondary ms-2" onclick="openEdit(' + show.id + ')"><i class="bi bi-pencil"></i></button>'
        + ' <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteShow(' + show.id + ')"><i class="bi bi-trash"></i></button>'
        : '';

    var exportBtns = IS_ADMIN ? ''
        + '<div class="d-flex gap-2 mt-2 mt-md-0">'
        + '<a href="?action=export_pre_show&show_id=' + show.id + '" class="btn btn-sm btn-outline-success" title="CSV para Daniel">'
        + '<i class="bi bi-download me-1"></i>Para Daniel</a>'
        + '<a href="?action=download_template&show_id=' + show.id + '" class="btn btn-sm btn-outline-secondary" title="Template post-show">'
        + '<i class="bi bi-file-earmark-arrow-down me-1"></i>Template post-show</a>'
        + '</div>' : '';

    var rows = items.length ? items.map(function(item) {
        var slotCell = IS_ADMIN
            ? '<td><span class="badge bg-secondary font-monospace slot-badge" style="cursor:pointer;min-width:2.5rem" '
              + 'onclick="editSlot(this,' + item.id + ',' + show.id + ')" title="Click para editar slot">'
              + esc(item.whatnot_slot || '—') + '</span></td>'
            : '<td class="font-monospace text-muted small">' + esc(item.whatnot_slot || '—') + '</td>';
        var bidCell = IS_ADMIN ? '<td class="text-warning">$' + fmt2(item.starting_bid_usd) + '</td>' : '';
        return '<tr>'
            + slotCell
            + '<td class="fw-semibold">' + esc(item.brand || '—') + '</td>'
            + '<td>' + esc(item.description) + '</td>'
            + '<td class="font-monospace small">' + esc(item.upc || '—') + '</td>'
            + '<td>' + esc(item.color || '—') + '</td>'
            + '<td>' + esc(item.size || '—') + '</td>'
            + '<td class="fw-bold">' + item.qty_listed + '</td>'
            + bidCell
            + '</tr>';
    }).join('') : '<tr><td colspan="9" class="text-center text-muted py-3">Sin productos en el lineup.</td></tr>';

    var bidHeader = IS_ADMIN ? '<th>Bid Inicial</th>' : '';

    document.getElementById('lineupPanel').innerHTML = ''
        + '<div class="card shadow-sm">'
        + '<div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-2">'
        + '<div>'
        + '<div class="fw-bold fs-5">' + esc(show.title) + adminEdit + '</div>'
        + '<div class="text-muted small">' + formatDate(show.scheduled_at) + (show.host ? ' · ' + esc(show.host) : '') + '</div>'
        + '</div>'
        + '<div class="d-flex flex-column align-items-end gap-1">'
        + '<span class="badge bg-' + STATUS_COLORS[show.status] + '">' + STATUS_LABELS[show.status] + '</span>'
        + exportBtns
        + '</div>'
        + '</div>'
        + '<div class="card-body p-0">'
        + '<table class="table table-sm mb-0">'
        + '<thead class="table-dark"><tr><th>Slot</th><th>Marca</th><th>Descripción</th><th>UPC</th><th>Color</th><th>Talla</th><th>Qty</th>' + bidHeader + '</tr></thead>'
        + '<tbody>' + rows + '</tbody>'
        + '</table>'
        + '</div>'
        + (items.length ? '<div class="card-footer text-muted small">' + items.length + ' productos · ' + items.reduce(function(a,b){return a+parseInt(b.qty_listed);},0) + ' unidades · <span class="text-info">Click en slot para editar</span></div>' : '')
        + '</div>';
}

function editSlot(el, spId, showId) {
    var current = el.textContent.trim() === '—' ? '' : el.textContent.trim();
    var input = document.createElement('input');
    input.type = 'text'; input.value = current;
    input.className = 'form-control form-control-sm font-monospace p-0 text-center';
    input.style.cssText = 'width:4rem;display:inline-block';
    input.maxLength = 10;
    el.replaceWith(input);
    input.focus(); input.select();
    function save() {
        var val = input.value.trim();
        apiFetch('?action=update_slot', { body: { id: spId, show_id: showId, whatnot_slot: val } }).then(function(d) {
            var badge = document.createElement('span');
            badge.className = 'badge bg-' + (val ? 'primary' : 'secondary') + ' font-monospace slot-badge';
            badge.style.cssText = 'cursor:pointer;min-width:2.5rem';
            badge.title = 'Click para editar slot';
            badge.textContent = val || '—';
            badge.onclick = function() { editSlot(badge, spId, showId); };
            input.replaceWith(badge);
            if (d.ok) toast('Slot actualizado.');
            else toast(d.error, 'error');
        });
    }
    input.addEventListener('blur', save);
    input.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } if (e.key === 'Escape') { input.value = current; input.blur(); } });
}

function formatDate(dt) {
    var d = new Date(dt);
    return d.toLocaleDateString('es-MX', {weekday:'short', day:'numeric', month:'short'}) + ' ' + d.toLocaleTimeString('es-MX', {hour:'2-digit', minute:'2-digit'});
}

<?php if ($is_admin): ?>
function openCreate() {
    document.getElementById('modalTitle').textContent = 'Nuevo show';
    document.getElementById('fId').value = '';
    document.getElementById('fTitle').value = '';
    document.getElementById('fDate').value = '';
    document.getElementById('fDuration').value = '2';
    document.getElementById('fHost').value = '';
    document.getElementById('fStatus').value = 'SCHEDULED';
    document.getElementById('fNotes').value = '';
    document.getElementById('formError').classList.add('d-none');
    _modal.show();
}

function openEdit(id) {
    apiFetch('?action=get&id=' + id).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        var r = d.data;
        document.getElementById('modalTitle').textContent = 'Editar show';
        document.getElementById('fId').value       = r.id;
        document.getElementById('fTitle').value    = r.title;
        document.getElementById('fDate').value     = r.scheduled_at.replace(' ','T').substring(0,16);
        document.getElementById('fDuration').value = r.estimated_duration_hrs;
        document.getElementById('fHost').value     = r.host_id || '';
        document.getElementById('fStatus').value   = r.status;
        document.getElementById('fNotes').value    = r.notes || '';
        document.getElementById('formError').classList.add('d-none');
        _modal.show();
    });
}

function saveShow() {
    var id    = document.getElementById('fId').value;
    var title = document.getElementById('fTitle').value.trim();
    var date  = document.getElementById('fDate').value;
    if (!title || !date) { document.getElementById('formError').textContent = 'Título y fecha son requeridos.'; document.getElementById('formError').classList.remove('d-none'); return; }
    var payload = { id: id ? parseInt(id) : undefined, title: title, scheduled_at: date.replace('T',' ') + ':00',
        estimated_duration_hrs: parseFloat(document.getElementById('fDuration').value),
        host_id: document.getElementById('fHost').value || null,
        status:  document.getElementById('fStatus').value,
        notes:   document.getElementById('fNotes').value.trim() };
    apiFetch('?action=' + (id ? 'update' : 'create'), { body: payload }).then(function(d) {
        if (!d.ok) { document.getElementById('formError').textContent = d.error; document.getElementById('formError').classList.remove('d-none'); return; }
        _modal.hide(); toast(id ? 'Show actualizado.' : 'Show creado.'); loadShows();
        if (d.data && d.data.id) loadLineup(d.data.id);
    });
}

function deleteShow(id) {
    if (!confirm('¿Eliminar este show? Se eliminarán sus productos del lineup.')) return;
    apiFetch('?action=delete&id=' + id, { method: 'POST' }).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        toast('Show eliminado.'); _activeShow = null;
        document.getElementById('lineupPanel').innerHTML = '<div class="card shadow-sm"><div class="card-body text-center text-muted py-5"><i class="bi bi-camera-video fs-1 d-block mb-2"></i>Selecciona un show para ver el lineup</div></div>';
        loadShows();
    });
}
<?php endif ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
