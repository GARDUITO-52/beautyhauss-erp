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

    if ($action === 'export_whatnot') {
        if (!$is_admin) { http_response_code(403); exit; }

        $batch_f = (int)($_GET['batch_id'] ?? 0);
        $brand_f = trim($_GET['brand'] ?? '');
        $mode    = $_GET['mode'] ?? 'sku'; // 'sku' | 'pulls'

        $conditions = ['p.stock_qty > 0'];
        $params     = [];
        if ($brand_f) { $conditions[] = "p.brand = ?";              $params[] = $brand_f; }
        if ($batch_f) { $conditions[] = "p.purchase_batch_id = ?";  $params[] = $batch_f; }
        $where = "WHERE " . implode(" AND ", $conditions);

        $stmt = $pdo->prepare("
            SELECT p.sku_internal, p.brand, p.description, p.color, p.size,
                   p.stock_qty, p.cost_usd, p.rescue_price_usd,
                   pb.investor
            FROM products p
            LEFT JOIN purchase_batches pb ON pb.id = p.purchase_batch_id
            $where
            ORDER BY pb.id ASC, p.brand ASC, p.description ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $filename = 'whatnot-' . ($mode === 'pulls' ? 'pulls' : 'sku') . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Category','Sub Category','Title','Description','Quantity','Type',
                        'Price','Shipping Profile','Offerable','Hazmat','Condition',
                        'Cost Per Item','SKU','Image URL 1']);

        $pull_n = 1;
        foreach ($rows as $p) {
            $sub_cat  = 'Makeup & Skincare';
            $hazmat   = 'Not Hazmat';
            $shipping = '4-7 oz';
            $price    = number_format((float)$p['rescue_price_usd'], 2, '.', '');
            $cost     = number_format((float)$p['cost_usd'],         2, '.', '');

            if ($mode === 'pulls') {
                // Mystery pull mode — one row per unit, generic title, sequential SKU
                $qty = (int)$p['stock_qty'];
                for ($i = 0; $i < $qty; $i++) {
                    fputcsv($out, [
                        'Beauty', 'Makeup & Skincare',
                        'beautyhauss Mystery Beauty Pull',
                        'Mystery beauty pull containing premium brand cosmetics. May include makeup, skincare, and beauty accessories from top brands.',
                        1, 'Buy it Now', $price, '4-7 oz', 'FALSE', 'Not Hazmat', 'New',
                        $cost, 'BH-PULL-' . str_pad($pull_n++, 4, '0', STR_PAD_LEFT), '',
                    ]);
                }
            } else {
                // SKU mode — one row per SKU with full stock qty
                $parts = array_filter([$p['brand'], $p['description'], $p['color'], $p['size']]);
                $title = implode(' - ', $parts);
                if (mb_strlen($title) > 80) $title = mb_substr($title, 0, 77) . '...';
                $desc  = $p['brand'] . ' - ' . $p['description'];
                if ($p['color']) $desc .= ' | Color: ' . $p['color'];
                if ($p['size'])  $desc .= ' | Size: '  . $p['size'];

                fputcsv($out, [
                    'Beauty', $sub_cat, $title, $desc,
                    $p['stock_qty'], 'Buy it Now', $price,
                    $shipping, 'FALSE', $hazmat, 'New',
                    $cost, $p['sku_internal'] ?: '', '',
                ]);
            }
        }
        fclose($out);
        logActivity($pdo, 'products', 'export_whatnot', null, $filename);
        exit;
    }

    jsonErr('Acción no reconocida.', 400);
}

$page_title   = 'Productos - beautyhauss ERP';
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
        <?php if ($is_admin): ?>
        <div class="col-12 col-md-auto d-flex gap-2">
          <div class="dropdown">
            <button class="btn btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
              <i class="bi bi-download me-1"></i>Whatnot CSV
            </button>
            <ul class="dropdown-menu">
              <li><h6 class="dropdown-header">Modo de exportación</h6></li>
              <li><a class="dropdown-item" href="#" onclick="exportWhatnot('sku');return false;">
                <i class="bi bi-list-ul me-2"></i><strong>Por SKU</strong> — 1 fila por producto
              </a></li>
              <li><a class="dropdown-item" href="#" onclick="exportWhatnot('pulls');return false;">
                <i class="bi bi-boxes me-2"></i><strong>Mystery Pulls</strong> — 1 fila por unidad
              </a></li>
              <li><hr class="dropdown-divider"></li>
              <li><p class="dropdown-item-text text-muted small mb-0">Respeta filtros activos.<br>Solo exporta stock &gt; 0.</p></li>
            </ul>
          </div>
        </div>
        <?php endif ?>

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

<?php if ($is_admin): ?>
<div id="selectionBar" class="d-none position-fixed bottom-0 start-50 translate-middle-x mb-4" style="z-index:1040;min-width:340px">
  <div class="card shadow-lg border-0" style="background:#1a202c;color:#fff">
    <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
      <span id="selectionCount" class="fw-bold fs-5" style="color:#d4537e"></span>
      <span class="text-muted small flex-grow-1">productos seleccionados</span>
      <button class="btn btn-sm fw-bold" style="background:#d4537e;color:#fff" onclick="openBulkModal()">
        <i class="bi bi-collection-play me-1"></i>Agregar al show
      </button>
      <button class="btn btn-sm btn-outline-secondary" onclick="clearSelection()" title="Limpiar selección"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
</div>

<div class="modal fade" id="modalBulkAdd" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">Agregar productos al show</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Show <span class="text-danger">*</span></label>
          <select id="bulkShowId" class="form-select bg-dark text-white border-secondary">
            <option value="">Cargando shows programados…</option>
          </select>
        </div>
        <div class="table-responsive" style="max-height:360px;overflow-y:auto">
          <table class="table table-sm table-dark mb-0">
            <thead class="sticky-top" style="top:0;background:#212529">
              <tr><th>Producto</th><th style="width:70px">Stock</th><th style="width:85px">Qty</th><th style="width:100px">Bid $</th><th style="width:36px"></th></tr>
            </thead>
            <tbody id="bulkTable"></tbody>
          </table>
        </div>
        <div id="bulkError" class="alert alert-danger py-2 mt-2 d-none"></div>
      </div>
      <div class="modal-footer border-secondary">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn fw-bold" style="background:#d4537e;color:#fff" id="btnBulkSubmit" onclick="submitBulkAdd()">Agregar al show</button>
      </div>
    </div>
  </div>
</div>
<?php endif ?>

<script>
var IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
var _sort = '', _dir = 'desc';
var _rowCache = {}, _selectedProducts = {}, _bulkModal = null;

document.addEventListener('DOMContentLoaded', function() {
    if (IS_ADMIN) _bulkModal = new bootstrap.Modal(document.getElementById('modalBulkAdd'));
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
        {label: IS_ADMIN ? '<input type="checkbox" id="checkAll" onchange="toggleAll(this)">' : '', isHtml: true},
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
        if (c.isHtml) return '<th style="width:36px">' + c.label + '</th>';
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
    _rowCache = {};
    var tb = document.getElementById('productsBody');
    if (!rows.length) { tb.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">Sin resultados con los filtros seleccionados.</td></tr>'; return; }
    tb.innerHTML = rows.map(function(r) {
        _rowCache[r.id] = r;
        var chkCell = IS_ADMIN
            ? '<td><input type="checkbox" class="prod-check" value="' + r.id + '"'
              + (_selectedProducts[r.id] ? ' checked' : '') + ' onchange="onProductCheck(this)"></td>'
            : '<td></td>';
        var cells = [
            chkCell,
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

function toggleAll(cb) {
    document.querySelectorAll('.prod-check').forEach(function(c) {
        c.checked = cb.checked;
        onProductCheck(c);
    });
}

function onProductCheck(cb) {
    var id = parseInt(cb.value);
    var r  = _rowCache[id];
    if (!r) return;
    if (cb.checked) {
        _selectedProducts[id] = Object.assign({}, r, { qty: r.stock_qty || 1 });
    } else {
        delete _selectedProducts[id];
    }
    updateSelectionBar();
}

function updateSelectionBar() {
    var count = Object.keys(_selectedProducts).length;
    var bar   = document.getElementById('selectionBar');
    if (!bar) return;
    bar.classList.toggle('d-none', count === 0);
    if (count) document.getElementById('selectionCount').textContent = count;
    var all   = document.querySelectorAll('.prod-check').length;
    var chkd  = document.querySelectorAll('.prod-check:checked').length;
    var allCb = document.getElementById('checkAll');
    if (allCb) allCb.checked = all > 0 && chkd === all;
}

function clearSelection() {
    _selectedProducts = {};
    document.querySelectorAll('.prod-check').forEach(function(c) { c.checked = false; });
    var cb = document.getElementById('checkAll');
    if (cb) cb.checked = false;
    updateSelectionBar();
}

function openBulkModal() {
    var prods = Object.values(_selectedProducts);
    if (!prods.length) return;
    document.getElementById('bulkTable').innerHTML = prods.map(function(p) {
        return '<tr data-pid="' + p.id + '">'
            + '<td><span class="fw-semibold">' + esc(p.brand || '') + '</span><br>'
            + '<span class="small text-muted">' + esc(p.description) + '</span></td>'
            + '<td>' + p.stock_qty + '</td>'
            + '<td><input type="number" class="form-control form-control-sm bg-dark text-white border-secondary bulk-qty"'
            + ' min="1" value="' + (p.qty || p.stock_qty || 1) + '" style="width:75px"></td>'
            + '<td><input type="number" class="form-control form-control-sm bg-dark text-white border-secondary bulk-bid"'
            + ' min="0" step="0.01" value="' + parseFloat(p.rescue_price_usd || 0).toFixed(2) + '" style="width:85px"></td>'
            + '<td><button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeFromBulk(' + p.id + ',this)">'
            + '<i class="bi bi-x"></i></button></td>'
            + '</tr>';
    }).join('');
    document.getElementById('btnBulkSubmit').textContent = 'Agregar ' + prods.length + ' al show';
    document.getElementById('bulkError').classList.add('d-none');
    var sel = document.getElementById('bulkShowId');
    sel.innerHTML = '<option value="">Cargando…</option>';
    apiFetch('shows.php?action=list&status=SCHEDULED').then(function(d) {
        if (!d.ok || !d.data.length) {
            sel.innerHTML = '<option value="">Sin shows programados</option>';
            return;
        }
        sel.innerHTML = '<option value="">— Selecciona un show —</option>'
            + d.data.map(function(s) {
                var dt = new Date(s.scheduled_at).toLocaleDateString('es-MX', {day:'numeric', month:'short', hour:'2-digit', minute:'2-digit'});
                return '<option value="' + s.id + '">' + esc(s.title) + ' · ' + dt + '</option>';
            }).join('');
    });
    _bulkModal.show();
}

function removeFromBulk(pid, btn) {
    btn.closest('tr').remove();
    delete _selectedProducts[pid];
    var cb = document.querySelector('.prod-check[value="' + pid + '"]');
    if (cb) cb.checked = false;
    var n = document.querySelectorAll('#bulkTable tr').length;
    document.getElementById('btnBulkSubmit').textContent = 'Agregar ' + n + ' al show';
    updateSelectionBar();
    if (n === 0) _bulkModal.hide();
}

function submitBulkAdd() {
    var showId = parseInt(document.getElementById('bulkShowId').value);
    var err    = document.getElementById('bulkError');
    if (!showId) { err.textContent = 'Selecciona un show.'; err.classList.remove('d-none'); return; }
    err.classList.add('d-none');
    var products = [];
    document.querySelectorAll('#bulkTable tr[data-pid]').forEach(function(tr) {
        products.push({
            product_id:       parseInt(tr.dataset.pid),
            qty_listed:       parseInt(tr.querySelector('.bulk-qty').value) || 1,
            starting_bid_usd: parseFloat(tr.querySelector('.bulk-bid').value) || 0
        });
    });
    if (!products.length) return;
    var btn = document.getElementById('btnBulkSubmit');
    btn.disabled = true; btn.textContent = 'Guardando…';
    apiFetch('shows.php?action=bulk_add_to_show', { body: { show_id: showId, products: products } }).then(function(d) {
        btn.disabled = false;
        if (!d.ok) { err.textContent = d.error; err.classList.remove('d-none'); btn.textContent = 'Agregar al show'; return; }
        _bulkModal.hide();
        clearSelection();
        var msg = d.data.added + ' ' + (d.data.added === 1 ? 'producto agregado' : 'productos agregados') + ' al show';
        if (d.data.skipped) msg += ' · ' + d.data.skipped + ' ya estaban en el lineup';
        toast(msg);
    });
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

function exportWhatnot(mode) {
    var params = new URLSearchParams({
        action: 'export_whatnot',
        mode:   mode,
        brand:    document.getElementById('brandFilter').value,
        batch_id: document.getElementById('batchFilter').value,
    });
    window.location.href = '?' + params.toString();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
