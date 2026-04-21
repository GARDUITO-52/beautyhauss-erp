<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$is_admin = current_user()['role'] === 'admin';

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'list') {
        $search       = trim($_GET['search'] ?? '');
        $brand_f      = trim($_GET['brand'] ?? '');
        $batch_f      = (int)($_GET['batch_id'] ?? 0);
        $stock_f      = $_GET['stock_filter'] ?? 'all';
        $sort_col     = $_GET['sort'] ?? '';
        $sort_dir     = strtolower($_GET['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
        $page         = max(1, (int)($_GET['page'] ?? 1));
        $limit        = 50; $offset = ($page - 1) * $limit;

        $allowed_sorts = ['stock_qty' => 'p.stock_qty', 'cost_usd' => 'p.cost_usd', 'rescue_price_usd' => 'p.rescue_price_usd'];
        $order_by = isset($allowed_sorts[$sort_col]) ? $allowed_sorts[$sort_col] . ' ' . $sort_dir : 'p.brand ASC, p.description ASC';

        $conditions = [];
        $params     = [];
        if ($search) {
            $conditions[] = "(p.brand LIKE ? OR p.description LIKE ? OR p.upc LIKE ? OR p.sku_internal LIKE ?)";
            $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
        }
        if ($brand_f) { $conditions[] = "p.brand = ?"; $params[] = $brand_f; }
        if ($batch_f) { $conditions[] = "p.purchase_batch_id = ?"; $params[] = $batch_f; }
        if ($stock_f === 'in')   { $conditions[] = "p.stock_qty > 0"; }
        if ($stock_f === 'out')  { $conditions[] = "p.stock_qty = 0"; }
        if ($stock_f === 'low')  { $conditions[] = "p.stock_qty > 0 AND p.stock_qty < 10"; }

        $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : '';
        $cnt   = $pdo->prepare("SELECT COUNT(*) FROM products p $where");
        $cnt->execute($params); $total = (int)$cnt->fetchColumn();

        $cols = $is_admin
            ? "p.id, p.sku_internal, p.brand, p.description, p.upc, p.color, p.size, p.stock_qty, p.cost_usd, p.rescue_price_usd"
            : "p.id, p.sku_internal, p.brand, p.description, p.upc, p.color, p.size, p.stock_qty";
        $stmt = $pdo->prepare("SELECT $cols FROM products p $where ORDER BY $order_by LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        jsonOk(['products' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, ceil($total / $limit))]);
    }

    if ($action === 'filters') {
        $brands  = $pdo->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
        $batches = $pdo->query("SELECT id, reference_no FROM purchase_batches ORDER BY id DESC")->fetchAll();
        jsonOk(['brands' => $brands, 'batches' => $batches]);
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
      <div class="row g-2">
        <div class="col-12 col-md-4">
          <input type="search" id="searchInput" class="form-control" placeholder="Buscar marca, descripción, UPC, SKU…">
        </div>
        <div class="col-6 col-md-2">
          <select id="brandFilter" class="form-select">
            <option value="">Todas las marcas</option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <select id="batchFilter" class="form-select">
            <option value="">Todos los lotes</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select id="stockFilter" class="form-select">
            <option value="all">Todo el stock</option>
            <option value="in">En stock</option>
            <option value="low">Bajo stock (&lt;10)</option>
            <option value="out">Sin stock</option>
          </select>
        </div>
        <div class="col-6 col-md-1">
          <button class="btn btn-outline-secondary w-100" onclick="resetFilters()" title="Limpiar filtros"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>
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
var _sort = '', _dir = 'desc';

document.addEventListener('DOMContentLoaded', function() {
    loadFilters();
    renderHead();
    loadProducts();
    var t;
    document.getElementById('searchInput').addEventListener('input', function() { clearTimeout(t); t = setTimeout(function() { loadProducts(1); }, 300); });
    document.getElementById('brandFilter').addEventListener('change', function() { loadProducts(1); });
    document.getElementById('batchFilter').addEventListener('change', function() { loadProducts(1); });
    document.getElementById('stockFilter').addEventListener('change', function() { loadProducts(1); });
});

function loadFilters() {
    apiFetch('?action=filters').then(function(d) {
        if (!d.ok) return;
        var bSel = document.getElementById('brandFilter');
        d.data.brands.forEach(function(b) {
            var o = document.createElement('option'); o.value = b; o.textContent = b; bSel.appendChild(o);
        });
        var lSel = document.getElementById('batchFilter');
        d.data.batches.forEach(function(b) {
            var o = document.createElement('option'); o.value = b.id; o.textContent = b.reference_no; lSel.appendChild(o);
        });
    });
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('brandFilter').value = '';
    document.getElementById('batchFilter').value = '';
    document.getElementById('stockFilter').value = 'all';
    _sort = ''; _dir = 'desc';
    renderHead();
    loadProducts(1);
}

function sortBy(col) {
    if (_sort === col) { _dir = _dir === 'desc' ? 'asc' : 'desc'; }
    else { _sort = col; _dir = 'desc'; }
    renderHead();
    loadProducts(1);
}

function sortIcon(col) {
    if (_sort !== col) return '<i class="bi bi-chevron-expand ms-1 text-secondary" style="font-size:.7rem"></i>';
    return _dir === 'desc'
        ? '<i class="bi bi-chevron-down ms-1" style="font-size:.7rem;color:#d4537e"></i>'
        : '<i class="bi bi-chevron-up ms-1" style="font-size:.7rem;color:#d4537e"></i>';
}

function renderHead() {
    var cols = [
        {label:'SKU', sort:null},
        {label:'Marca', sort:null},
        {label:'Descripción', sort:null},
        {label:'UPC', sort:null},
        {label:'Color', sort:null},
        {label:'Talla', sort:null},
        {label:'Stock', sort:'stock_qty'},
    ];
    if (IS_ADMIN) cols = cols.concat([
        {label:'Costo USD', sort:'cost_usd'},
        {label:'Rescue Price', sort:'rescue_price_usd'},
    ]);
    document.getElementById('tableHead').innerHTML = '<tr>' + cols.map(function(c) {
        if (!c.sort) return '<th>' + c.label + '</th>';
        return '<th style="cursor:pointer;user-select:none" onclick="sortBy(\'' + c.sort + '\')">'
            + c.label + sortIcon(c.sort) + '</th>';
    }).join('') + '</tr>';
}

function loadProducts(page) {
    page = page || 1;
    var qs = '?action=list'
        + '&page=' + page
        + '&search=' + encodeURIComponent(document.getElementById('searchInput').value)
        + '&brand=' + encodeURIComponent(document.getElementById('brandFilter').value)
        + '&batch_id=' + document.getElementById('batchFilter').value
        + '&stock_filter=' + document.getElementById('stockFilter').value
        + (_sort ? '&sort=' + _sort + '&dir=' + _dir : '');
    apiFetch(qs).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        renderTable(d.data.products);
        renderPagination(d.data.page, d.data.pages, d.data.total);
    });
}

function renderTable(rows) {
    var tb = document.getElementById('productsBody');
    if (!rows.length) { tb.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">Sin resultados con los filtros seleccionados.</td></tr>'; return; }
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
            '<td class="text-warning fw-bold">' + fmt2(r.rescue_price_usd) + '</td>',
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
