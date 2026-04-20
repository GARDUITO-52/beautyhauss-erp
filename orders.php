<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$is_admin = current_user()['role'] === 'admin';
$me       = current_user();

// ── AJAX ───────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'list') {
        $show_id  = (int)($_GET['show_id'] ?? 0);
        $status   = $_GET['status'] ?? '';
        $packed   = $_GET['packed'] ?? '';   // 'yes' | 'no' | ''
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $limit    = 50; $offset = ($page - 1) * $limit;

        $conds = [];
        $params = [];
        if ($show_id) { $conds[] = 'o.show_id = ?'; $params[] = $show_id; }
        if ($status)  { $conds[] = 'o.status = ?';  $params[] = $status; }
        if ($packed === 'no')  { $conds[] = 'o.packed_at IS NULL'; }
        if ($packed === 'yes') { $conds[] = 'o.packed_at IS NOT NULL'; }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

        $total = $pdo->prepare("SELECT COUNT(*) FROM orders o $where");
        $total->execute($params); $total = (int)$total->fetchColumn();

        if ($is_admin) {
            $cols = "o.id, o.whatnot_order_id, o.buyer_username, o.sale_amount_usd, o.net_earnings_usd, o.cogs_usd, o.order_date, o.status, o.packed_at, u.name AS packed_by_name";
        } else {
            $cols = "o.id, o.whatnot_order_id, o.buyer_username, o.order_date, o.status, o.packed_at, u.name AS packed_by_name";
        }

        $stmt = $pdo->prepare("SELECT $cols
            FROM orders o LEFT JOIN users u ON u.id = o.packed_by
            $where ORDER BY o.packed_at IS NOT NULL, o.order_date ASC
            LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        // Load items per order
        if ($orders) {
            $ids   = array_column($orders, 'id');
            $in    = implode(',', array_fill(0, count($ids), '?'));
            $items = $pdo->prepare("SELECT oi.order_id, oi.qty, p.brand, p.description, p.upc
                FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id IN ($in) ORDER BY oi.id");
            $items->execute($ids);
            $grouped = [];
            foreach ($items->fetchAll() as $item) {
                $grouped[$item['order_id']][] = $item;
            }
            foreach ($orders as &$o) {
                $o['items'] = $grouped[$o['id']] ?? [];
            }
            unset($o);
        }

        jsonOk(['orders' => $orders, 'total' => $total, 'page' => $page, 'pages' => max(1, ceil($total / $limit))]);
    }

    if ($action === 'pack') {
        csrfGuard();
        $d   = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids = array_filter(array_map('intval', $d['ids'] ?? []));
        if (!$ids) jsonErr('Sin órdenes seleccionadas.');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$me['id']], $ids);
        $pdo->prepare("UPDATE orders SET packed_at=NOW(), packed_by=? WHERE id IN ($in) AND packed_at IS NULL")
            ->execute($params);
        logActivity($pdo, 'orders', 'pack', implode(',', $ids), count($ids) . ' órdenes empacadas');
        jsonOk(['count' => count($ids)]);
    }

    if ($action === 'unpack' && $is_admin) {
        csrfGuard();
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("UPDATE orders SET packed_at=NULL, packed_by=NULL WHERE id=?")->execute([$id]);
        jsonOk();
    }

    if ($action === 'shows_list') {
        $rows = $pdo->query("SELECT id, title, scheduled_at FROM shows WHERE status IN ('COMPLETED','LIVE','SCHEDULED') ORDER BY scheduled_at DESC LIMIT 50")->fetchAll();
        jsonOk($rows);
    }

    jsonErr('Acción no reconocida.', 400);
}

// ── Page ───────────────────────────────────────────────────────────────────
$page_title   = 'Órdenes — beautyhauss ERP';
$current_page = 'orders';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 fw-bold">Órdenes</h1>
    <div class="d-flex gap-2">
      <?php if ($is_admin): ?>
      <button class="btn btn-sm btn-outline-secondary" onclick="exportCsv()">
        <i class="bi bi-download me-1"></i>Export CSV
      </button>
      <?php endif ?>
    </div>
  </div>

  <!-- Filters -->
  <div class="card shadow-sm mb-3">
    <div class="card-body py-2">
      <div class="row g-2 align-items-center">
        <div class="col-md-4">
          <select id="showFilter" class="form-select form-select-sm">
            <option value="">Todos los shows</option>
          </select>
        </div>
        <div class="col-md-3">
          <select id="packedFilter" class="form-select form-select-sm">
            <option value="">Todas las órdenes</option>
            <option value="no" selected>Por empacar</option>
            <option value="yes">Ya empacadas</option>
          </select>
        </div>
        <?php if ($is_admin): ?>
        <div class="col-md-3">
          <select id="statusFilter" class="form-select form-select-sm">
            <option value="">Todos los status</option>
            <option value="FULFILLED" selected>Fulfilled</option>
            <option value="UNFULFILLED">Unfulfilled</option>
            <option value="RETURNED">Returned</option>
            <option value="DISPUTE">Dispute</option>
          </select>
        </div>
        <?php endif ?>
        <div class="col-md-2 ms-auto text-end">
          <button id="btnPackSelected" class="btn btn-sm fw-bold d-none" style="background:#d4537e;color:#fff" onclick="packSelected()">
            <i class="bi bi-check2-all me-1"></i>Marcar empacadas
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Counters -->
  <div id="counters" class="mb-3 d-flex gap-3 small text-muted"></div>

  <!-- Table -->
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover mb-0" id="ordersTable">
        <thead class="table-dark" id="ordersHead"></thead>
        <tbody id="ordersBody">
          <tr><td colspan="7" class="text-center text-muted py-4">Cargando…</td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center" id="pagination" style="display:none!important"></div>
  </div>
</div>

<script>
var IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
var _page    = 1;

document.addEventListener('DOMContentLoaded', function() {
    renderHead();
    loadShowsList();
    loadOrders();
    ['showFilter','packedFilter' <?= $is_admin ? ",'statusFilter'" : '' ?>].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', function() { _page = 1; loadOrders(); });
    });
    document.getElementById('ordersBody').addEventListener('change', updatePackBtn);
});

function renderHead() {
    var cols = ['<th><input type="checkbox" id="checkAll" onchange="toggleAll(this)"></th>','<th>Orden Whatnot</th>','<th>Buyer</th>','<th>Productos</th>','<th>Fecha</th>','<th>Estado empaque</th>'];
    if (IS_ADMIN) cols.splice(4, 0, '<th>Venta USD</th>','<th>Net USD</th>');
    document.getElementById('ordersHead').innerHTML = '<tr>' + cols.join('') + '</tr>';
}

function loadShowsList() {
    apiFetch('?action=shows_list').then(function(d) {
        if (!d.ok) return;
        var sel = document.getElementById('showFilter');
        d.data.forEach(function(s) {
            var opt = document.createElement('option');
            opt.value = s.id; opt.textContent = s.title;
            sel.appendChild(opt);
        });
    });
}

function loadOrders(page) {
    _page = page || _page;
    var params = new URLSearchParams({
        action: 'list', page: _page,
        show_id: document.getElementById('showFilter').value,
        packed:  document.getElementById('packedFilter').value,
    });
    if (IS_ADMIN) {
        var sf = document.getElementById('statusFilter');
        if (sf) params.set('status', sf.value);
    }
    apiFetch('?' + params.toString()).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        renderOrders(d.data.orders);
        renderPagination(d.data.page, d.data.pages, d.data.total);
        updatePackBtn();
    });
}

function renderOrders(orders) {
    var tb = document.getElementById('ordersBody');
    if (!orders.length) { tb.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Sin órdenes.</td></tr>'; return; }
    tb.innerHTML = orders.map(function(o) {
        var packed = o.packed_at
            ? '<span class="badge bg-success"><i class="bi bi-check2"></i> Empacada</span>'
            + '<div class="text-muted" style="font-size:.7rem">' + formatTs(o.packed_at) + (o.packed_by_name ? ' · ' + esc(o.packed_by_name) : '') + '</div>'
            : '<span class="badge bg-warning text-dark">Por empacar</span>';
        var items = (o.items || []).map(function(it) {
            return '<div class="small"><span class="fw-semibold">' + esc(it.brand||'') + '</span> ' + esc(it.description) + ' ×' + it.qty
                + (it.upc ? ' <span class="text-muted font-monospace">' + esc(it.upc) + '</span>' : '') + '</div>';
        }).join('') || '<span class="text-muted small">Sin items</span>';

        var chk = !o.packed_at ? '<input type="checkbox" class="order-check" value="' + o.id + '">' : '<i class="bi bi-check2 text-success"></i>';
        var cells = [
            '<td>' + chk + '</td>',
            '<td class="fw-semibold small font-monospace">' + esc(o.whatnot_order_id) + '</td>',
            '<td>' + esc(o.buyer_username || '—') + '</td>',
            '<td>' + items + '</td>',
            '<td class="text-muted small">' + (o.order_date ? o.order_date : '—') + '</td>',
            '<td>' + packed + '</td>',
        ];
        if (IS_ADMIN) cells.splice(4, 0,
            '<td>' + fmt2(o.sale_amount_usd) + '</td>',
            '<td class="' + (parseFloat(o.net_earnings_usd) >= 0 ? 'text-success' : 'text-danger') + '">' + fmt2(o.net_earnings_usd) + '</td>'
        );
        return '<tr class="' + (o.packed_at ? 'table-success' : '') + '">' + cells.join('') + '</tr>';
    }).join('');
}

function toggleAll(cb) {
    document.querySelectorAll('.order-check').forEach(function(c) { c.checked = cb.checked; });
    updatePackBtn();
}

function updatePackBtn() {
    var selected = document.querySelectorAll('.order-check:checked').length;
    var btn = document.getElementById('btnPackSelected');
    if (selected > 0) { btn.classList.remove('d-none'); btn.textContent = '✓ Marcar ' + selected + ' empacadas'; }
    else btn.classList.add('d-none');
    var all = document.getElementById('checkAll');
    if (all) { var total = document.querySelectorAll('.order-check').length; all.checked = total > 0 && selected === total; }
}

function packSelected() {
    var ids = Array.from(document.querySelectorAll('.order-check:checked')).map(function(c) { return parseInt(c.value); });
    if (!ids.length) return;
    apiFetch('?action=pack', { body: { ids: ids } }).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        toast(d.data.count + ' órdenes marcadas como empacadas.');
        loadOrders();
    });
}

function renderPagination(page, pages, total) {
    var el = document.getElementById('pagination');
    if (pages <= 1) { el.style.display = 'none'; return; }
    el.style.display = '';
    el.innerHTML = '<span class="text-muted small">' + total + ' órdenes · Página ' + page + ' de ' + pages + '</span>'
        + '<div>'
        + (page > 1 ? '<button class="btn btn-sm btn-outline-secondary me-1" onclick="loadOrders(' + (page-1) + ')">‹</button>' : '')
        + (page < pages ? '<button class="btn btn-sm btn-outline-secondary" onclick="loadOrders(' + (page+1) + ')">›</button>' : '')
        + '</div>';
}

function formatTs(ts) {
    if (!ts) return '';
    var d = new Date(ts);
    return d.toLocaleDateString('es-MX', {day:'2-digit', month:'short'}) + ' ' + d.toLocaleTimeString('es-MX', {hour:'2-digit', minute:'2-digit'});
}

function exportCsv() { window.location = '?action=export'; }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
