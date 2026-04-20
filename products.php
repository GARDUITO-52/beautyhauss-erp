<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$is_admin = current_user()['role'] === 'admin';

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'list') {
        $search = trim($_GET['search'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 50; $offset = ($page - 1) * $limit;
        $where  = $search ? "WHERE p.brand LIKE ? OR p.description LIKE ? OR p.upc LIKE ? OR p.sku_internal LIKE ?" : '';
        $params = $search ? ["%$search%","%$search%","%$search%","%$search%"] : [];
        $total  = $pdo->prepare("SELECT COUNT(*) FROM products p $where");
        $total->execute($params); $total = (int)$total->fetchColumn();
        $cols   = $is_admin
            ? "p.id, p.sku_internal, p.brand, p.description, p.upc, p.color, p.size, p.stock_qty, p.cost_usd, p.cost_mxn, p.rescue_price_mxn"
            : "p.id, p.sku_internal, p.brand, p.description, p.upc, p.color, p.size, p.stock_qty";
        $stmt   = $pdo->prepare("SELECT $cols FROM products p $where ORDER BY p.brand, p.description LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        jsonOk(['products' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, ceil($total / $limit))]);
    }

    jsonErr('Acción no reconocida.', 400);
}

$page_title   = 'Productos — beautyhauss ERP';
$current_page = 'products';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 fw-bold">Productos</h1>
    <?php if ($is_admin): ?>
    <span class="text-muted small">Catálogo completo — costos visibles solo para admins</span>
    <?php endif ?>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body py-2">
      <input type="search" id="searchInput" class="form-control" placeholder="Buscar por marca, descripción, UPC o SKU…">
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead class="table-dark" id="tableHead"></thead>
        <tbody id="productsBody">
          <tr><td colspan="9" class="text-center text-muted py-4">Cargando…</td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center" id="pagination" style="display:none!important"></div>
  </div>
</div>

<script>
var IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', function() {
    renderHead();
    loadProducts();
    var t;
    document.getElementById('searchInput').addEventListener('input', function() { clearTimeout(t); t = setTimeout(loadProducts, 300); });
});

function renderHead() {
    var cols = ['SKU','Marca','Descripción','UPC','Color','Talla','Stock'];
    if (IS_ADMIN) cols = cols.concat(['Costo USD','Costo MXN','Rescue Price']);
    document.getElementById('tableHead').innerHTML = '<tr>' + cols.map(function(c) { return '<th>' + c + '</th>'; }).join('') + '</tr>';
}

function loadProducts(page) {
    page = page || 1;
    var search = document.getElementById('searchInput').value;
    apiFetch('?action=list&page=' + page + '&search=' + encodeURIComponent(search))
        .then(function(d) {
            if (!d.ok) { toast(d.error, 'error'); return; }
            renderTable(d.data.products);
            renderPagination(d.data.page, d.data.pages, d.data.total);
        });
}

function renderTable(rows) {
    var tb = document.getElementById('productsBody');
    if (!rows.length) { tb.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">Sin productos registrados.</td></tr>'; return; }
    var colspan = IS_ADMIN ? 10 : 7;
    tb.innerHTML = rows.map(function(r) {
        var cells = [
            '<td class="text-muted small">' + esc(r.sku_internal || '—') + '</td>',
            '<td class="fw-semibold">' + esc(r.brand || '—') + '</td>',
            '<td>' + esc(r.description) + '</td>',
            '<td class="font-monospace small">' + esc(r.upc || '—') + '</td>',
            '<td>' + esc(r.color || '—') + '</td>',
            '<td>' + esc(r.size || '—') + '</td>',
            '<td class="fw-bold ' + (r.stock_qty <= 0 ? 'text-danger' : r.stock_qty < 5 ? 'text-warning' : 'text-success') + '">' + r.stock_qty + '</td>',
        ];
        if (IS_ADMIN) cells = cells.concat([
            '<td>' + fmt2(r.cost_usd) + '</td>',
            '<td>' + fmt2(r.cost_mxn) + '</td>',
            '<td class="text-warning fw-bold">' + fmt2(r.rescue_price_mxn) + '</td>',
        ]);
        return '<tr>' + cells.join('') + '</tr>';
    }).join('');
}

function renderPagination(page, pages, total) {
    var el = document.getElementById('pagination');
    if (pages <= 1) { el.style.display = 'none'; return; }
    el.style.display = '';
    el.innerHTML = '<span class="text-muted small">' + total + ' productos · Página ' + page + ' de ' + pages + '</span>'
        + '<div>'
        + (page > 1 ? '<button class="btn btn-sm btn-outline-secondary me-1" onclick="loadProducts(' + (page-1) + ')">‹</button>' : '')
        + (page < pages ? '<button class="btn btn-sm btn-outline-secondary" onclick="loadProducts(' + (page+1) + ')">›</button>' : '')
        + '</div>';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
