<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

// ── AJAX ───────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── Batch list ──
    if ($action === 'list') {
        $rows = $pdo->query("
            SELECT b.id, b.reference_no, b.batch_date, b.total_usd,
                   b.investor, b.status, b.notes,
                   s.name AS supplier_name,
                   COUNT(bi.id) AS item_count,
                   COALESCE(SUM(bi.qty), 0) AS unit_count
            FROM purchase_batches b
            JOIN suppliers s ON s.id = b.supplier_id
            LEFT JOIN purchase_batch_items bi ON bi.batch_id = b.id
            GROUP BY b.id ORDER BY b.batch_date DESC, b.id DESC
        ")->fetchAll();
        jsonOk($rows);
    }

    // ── Batch get ──
    if ($action === 'get') {
        $id  = (int)($_GET['id'] ?? 0);
        $row = $pdo->prepare("SELECT b.*, s.name AS supplier_name FROM purchase_batches b JOIN suppliers s ON s.id=b.supplier_id WHERE b.id=?");
        $row->execute([$id]); $row = $row->fetch();
        if (!$row) jsonErr('No encontrado.', 404);
        jsonOk($row);
    }

    // ── Batch items ──
    if ($action === 'items') {
        $id    = (int)($_GET['id'] ?? 0);
        $items = $pdo->prepare("SELECT * FROM purchase_batch_items WHERE batch_id=? ORDER BY brand, description");
        $items->execute([$id]);
        jsonOk($items->fetchAll());
    }

    // ── Batch create ──
    if ($action === 'create') {
        csrfGuard();
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($d['supplier_id']) || empty($d['reference_no']) || empty($d['batch_date'])) jsonErr('Proveedor, referencia y fecha son requeridos.');
        $pdo->prepare("INSERT INTO purchase_batches (supplier_id, reference_no, batch_date, total_usd, investor, status, notes) VALUES (?,?,?,?,?,?,?)")
            ->execute([$d['supplier_id'], trim($d['reference_no']), $d['batch_date'], $d['total_usd'] ?? 0, $d['investor'] ?? 'JACK', $d['status'] ?? 'PENDING', trim($d['notes'] ?? '')]);
        $id = $pdo->lastInsertId();
        logActivity($pdo, 'batches', 'create', $id, $d['reference_no']);
        jsonOk(['id' => $id]);
    }

    // ── Batch update ──
    if ($action === 'update') {
        csrfGuard();
        $d  = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($d['id'] ?? 0);
        if (!$id || empty($d['supplier_id'])) jsonErr('Datos inválidos.');
        $pdo->prepare("UPDATE purchase_batches SET supplier_id=?, reference_no=?, batch_date=?, total_usd=?, investor=?, status=?, notes=? WHERE id=?")
            ->execute([$d['supplier_id'], trim($d['reference_no']), $d['batch_date'], $d['total_usd'] ?? 0, $d['investor'] ?? 'JACK', $d['status'] ?? 'PENDING', trim($d['notes'] ?? ''), $id]);
        logActivity($pdo, 'batches', 'update', $id, $d['reference_no']);
        jsonOk();
    }

    // ── CSV Import ──
    if ($action === 'import_csv') {
        csrfGuard();
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        if (!$batch_id) jsonErr('batch_id requerido.');
        if (empty($_FILES['csv_file'])) jsonErr('Archivo CSV requerido.');

        $file = $_FILES['csv_file']['tmp_name'];
        if (!is_readable($file)) jsonErr('No se pudo leer el archivo.');

        // Read CSV
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);
        if (!$headers) { fclose($handle); jsonErr('CSV vacío o inválido.'); }
        $headers = array_map('trim', $headers);

        // Column mapping from POST (header_index => field_name)
        $mapping = json_decode($_POST['mapping'] ?? '{}', true);

        $inserted = 0; $skipped = 0; $errors = [];
        $stmt = $pdo->prepare("INSERT INTO purchase_batch_items
            (batch_id, supplier_product_id, brand, description, upc, packaging_type, color, size, qty, unit_cost_usd)
            VALUES (?,?,?,?,?,?,?,?,?,?)");

        $pdo->beginTransaction();
        try {
            $row_num = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $row_num++;
                $get = function($field) use ($mapping, $row, $headers) {
                    foreach ($mapping as $idx => $f) {
                        if ($f === $field && isset($row[$idx])) return trim($row[$idx]);
                    }
                    return '';
                };

                $desc = $get('description');
                if (!$desc) { $skipped++; continue; }

                $qty  = (int)str_replace(',', '', $get('qty') ?: '0');
                $cost = (float)str_replace(['$',','], '', $get('unit_cost_usd') ?: '0');
                if ($qty <= 0 || $cost <= 0) {
                    $errors[] = "Fila $row_num: qty/costo inválido ($qty / \${$cost}) — saltada";
                    $skipped++; continue;
                }

                $stmt->execute([$batch_id, $get('supplier_product_id'), $get('brand'), $desc, $get('upc'), $get('packaging_type'), $get('color'), $get('size'), $qty, $cost]);
                $inserted++;
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            fclose($handle);
            jsonErr('Error al importar: ' . $e->getMessage());
        }
        fclose($handle);

        // Recalc batch totals
        $pdo->prepare("UPDATE purchase_batches SET total_usd = (SELECT COALESCE(SUM(qty * unit_cost_usd),0) FROM purchase_batch_items WHERE batch_id=?) WHERE id=?")
            ->execute([$batch_id, $batch_id]);

        logActivity($pdo, 'batches', 'csv_import', $batch_id, "$inserted items importados");
        jsonOk(['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors]);
    }

    // ── Quick supplier create ──
    if ($action === 'quick_supplier') {
        csrfGuard();
        $d    = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($d['name'] ?? '');
        if (!$name) jsonErr('Nombre requerido.');
        $pdo->prepare("INSERT INTO suppliers (name) VALUES (?)")->execute([$name]);
        $id = $pdo->lastInsertId();
        logActivity($pdo, 'suppliers', 'create', $id, $name);
        jsonOk(['id' => $id, 'name' => $name]);
    }

    // ── Generate Products ──
    if ($action === 'generate_products') {
        csrfGuard();
        $batch_id = (int)($_GET['batch_id'] ?? 0);
        if (!$batch_id) jsonErr('batch_id requerido.');

        $exists_batch = $pdo->prepare("SELECT COUNT(*) FROM purchase_batches WHERE id=?");
        $exists_batch->execute([$batch_id]);
        if (!(int)$exists_batch->fetchColumn()) jsonErr('Lote no encontrado.');

        $items = $pdo->prepare("SELECT * FROM purchase_batch_items WHERE batch_id=?");
        $items->execute([$batch_id]); $items = $items->fetchAll();

        $created = 0; $skipped = 0;
        $insert  = $pdo->prepare("INSERT INTO products
            (batch_item_id, brand, description, upc, color, size, packaging_type, cost_usd, rescue_price_usd, stock_qty)
            VALUES (?,?,?,?,?,?,?,?,?,?)");

        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $exists = $pdo->prepare("SELECT COUNT(*) FROM products WHERE batch_item_id=?");
                $exists->execute([$item['id']]);
                if ((int)$exists->fetchColumn() > 0) { $skipped++; continue; }

                $cost_usd = (float)$item['unit_cost_usd'];
                // Rescue price: break-even formula (cost + $0.30 flat) / (1 - 10.9% fees)
                // Floor: $1.00 for cost < $1.00, $2.00 for cost >= $1.00
                $rescue   = round(($cost_usd + 0.30) / (1 - 0.109), 2);
                $floor    = $cost_usd >= 1.00 ? 2.00 : 1.00;
                $rescue   = max($floor, $rescue);
                $insert->execute([$item['id'], $item['brand'], $item['description'], $item['upc'], $item['color'], $item['size'], $item['packaging_type'], $cost_usd, $rescue, $item['qty']]);
                $created++;
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonErr('Error: ' . $e->getMessage());
        }

        logActivity($pdo, 'batches', 'generate_products', $batch_id, "$created productos generados");

        // Streaming + goal estimate for this batch
        $st = $pdo->prepare("
            SELECT COUNT(*) AS skus,
                   COALESCE(SUM(p.stock_qty), 0) AS units,
                   COALESCE(AVG(p.rescue_price_usd), 0) AS avg_rescue
            FROM products p
            INNER JOIN purchase_batch_items bi ON bi.id = p.batch_item_id
            WHERE bi.batch_id = ?
        ");
        $st->execute([$batch_id]); $st = $st->fetch();
        $stream_hours    = round((int)$st['units'] * 5 / 3600, 1);
        $potential_rev   = round((float)$st['avg_rescue'] * (int)$st['units'], 2);

        $cfg_row = $pdo->query("SELECT config_key, config_value FROM system_config")->fetchAll(PDO::FETCH_KEY_PAIR);
        $goal_amt        = (float)($cfg_row['goal_amount_usd'] ?? 60000);
        $streamer_hourly = (float)($cfg_row['streamer_hourly_usd'] ?? 50);
        $stream_cost     = round($stream_hours * $streamer_hourly, 2);

        jsonOk([
            'created'       => $created,
            'skipped'       => $skipped,
            'stream_skus'   => (int)$st['skus'],
            'stream_units'  => (int)$st['units'],
            'stream_hours'  => $stream_hours,
            'stream_cost'   => $stream_cost,
            'avg_rescue'    => round((float)$st['avg_rescue'], 2),
            'potential_rev' => $potential_rev,
            'goal'          => $goal_amt,
            'goal_pct'      => $goal_amt > 0 ? round($potential_rev / $goal_amt * 100, 1) : 0,
        ]);
    }

    // ── Batch P&L ──
    if ($action === 'batch_pnl') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonErr('batch_id requerido.');

        $count = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN purchase_batch_items bi ON bi.id = p.batch_item_id WHERE bi.batch_id = ?");
        $count->execute([$id]);
        if (!(int)$count->fetchColumn()) jsonOk(['has_products' => false]);

        $rows = $pdo->prepare("
            SELECT p.cost_usd, p.rescue_price_usd,
                   SUM(p.stock_qty) AS units,
                   SUM(p.stock_qty * p.rescue_price_usd) AS gross_revenue,
                   SUM(p.stock_qty * p.cost_usd) AS cogs
            FROM products p
            JOIN purchase_batch_items bi ON bi.id = p.batch_item_id
            WHERE bi.batch_id = ?
            GROUP BY p.rescue_price_usd, p.cost_usd
            ORDER BY p.cost_usd ASC
        ");
        $rows->execute([$id]);

        $groups = [];
        $t_units = $t_gross = $t_fees = $t_net = $t_cogs = $t_profit = 0;
        foreach ($rows->fetchAll() as $row) {
            $units  = (int)$row['units'];
            $gross  = (float)$row['gross_revenue'];
            $cogs   = (float)$row['cogs'];
            $fees   = round($gross * 0.109 + $units * 0.30, 2);
            $net    = round($gross - $fees, 2);
            $profit = round($net - $cogs, 2);
            $groups[] = [
                'cost_usd'      => (float)$row['cost_usd'],
                'rescue_price'  => (float)$row['rescue_price_usd'],
                'units'         => $units,
                'gross_revenue' => round($gross, 2),
                'fees'          => $fees,
                'net_seller'    => $net,
                'cogs'          => round($cogs, 2),
                'gross_profit'  => $profit,
            ];
            $t_units  += $units;
            $t_gross  += $gross;
            $t_fees   += $fees;
            $t_net    += $net;
            $t_cogs   += $cogs;
            $t_profit += $profit;
        }

        $cfg = $pdo->query("SELECT config_key, config_value FROM system_config")->fetchAll(PDO::FETCH_KEY_PAIR);
        $streamer_hourly = (float)($cfg['streamer_hourly_usd'] ?? 50);
        $stream_hours    = round($t_units * 5 / 3600, 1);
        $stream_cost     = round($stream_hours * $streamer_hourly, 2);

        jsonOk([
            'has_products' => true,
            'groups'       => $groups,
            'totals'       => [
                'units'            => $t_units,
                'gross_revenue'    => round($t_gross, 2),
                'fees'             => round($t_fees, 2),
                'net_seller'       => round($t_net, 2),
                'cogs'             => round($t_cogs, 2),
                'gross_profit'     => round($t_profit, 2),
                'stream_hours'     => $stream_hours,
                'stream_cost'      => $stream_cost,
                'streamer_hourly'  => $streamer_hourly,
                'net_profit'       => round($t_profit - $stream_cost, 2),
            ],
        ]);
    }

    jsonErr('Acción no reconocida.', 400);
}

// ── Page ───────────────────────────────────────────────────────────────────
$suppliers    = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();
$page_title   = 'Lotes de Compra — beautyhauss ERP';
$current_page = 'batches';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 fw-bold">Lotes de Compra</h1>
    <button class="btn btn-sm fw-bold" style="background:#d4537e;color:#fff" onclick="openCreate()">
      <i class="bi bi-plus-lg me-1"></i>Nuevo lote
    </button>
  </div>

  <div class="row g-3">
    <!-- Batch list -->
    <div class="col-md-4">
      <div class="card shadow-sm">
        <div class="list-group list-group-flush" id="batchList">
          <div class="list-group-item text-muted text-center py-3">Cargando…</div>
        </div>
      </div>
    </div>

    <!-- Batch detail -->
    <div class="col-md-8">
      <div id="detailPanel">
        <div class="card shadow-sm">
          <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inboxes fs-1 d-block mb-2"></i>
            Selecciona un lote para ver su detalle
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Create/Edit batch -->
<div class="modal fade" id="modalBatch" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title" id="batchModalTitle">Nuevo lote de compra</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="bId">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Proveedor <span class="text-danger">*</span></label>
            <div class="input-group">
              <select id="bSupplier" class="form-select bg-dark text-white border-secondary">
                <option value="">— Seleccionar —</option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach ?>
              </select>
              <button type="button" class="btn btn-outline-secondary" title="Nuevo proveedor" onclick="openQuickSupplier()">＋</button>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Referencia / N° Factura <span class="text-danger">*</span></label>
            <input type="text" id="bRef" class="form-control bg-dark text-white border-secondary" placeholder="ej. SO2504460">
          </div>
        </div>
        <div class="row g-3 mt-0">
          <div class="col-md-4">
            <label class="form-label">Fecha <span class="text-danger">*</span></label>
            <input type="date" id="bDate" class="form-control bg-dark text-white border-secondary">
          </div>
          <div class="col-md-4">
            <label class="form-label">Total USD</label>
            <input type="number" id="bTotal" class="form-control bg-dark text-white border-secondary" step="0.01" min="0">
          </div>
          <div class="col-md-4">
            <label class="form-label">Inversionista</label>
            <select id="bInvestor" class="form-select bg-dark text-white border-secondary">
              <option value="JACK">Jack</option>
              <option value="NESIM">Nesim</option>
              <option value="ALEX">Alex</option>
              <option value="SIMON">Simon</option>
              <option value="COMPARTIDO">Compartido</option>
            </select>
          </div>
        </div>
        <div class="row g-3 mt-0">
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select id="bStatus" class="form-select bg-dark text-white border-secondary">
              <option value="PENDING">Pendiente</option>
              <option value="RECEIVED">Recibido</option>
              <option value="PARTIAL">Parcial</option>
            </select>
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label">Notas</label>
          <textarea id="bNotes" class="form-control bg-dark text-white border-secondary" rows="2"></textarea>
        </div>
        <div id="batchFormError" class="alert alert-danger py-2 mt-3 d-none"></div>
      </div>
      <div class="modal-footer border-secondary">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn fw-bold" style="background:#d4537e;color:#fff" onclick="saveBatch()">Guardar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: CSV Import -->
<div class="modal fade" id="modalCsv" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-xl">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">Importar CSV — <span id="csvBatchName"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Step 1: Upload -->
        <div id="csvStep1">
          <p class="text-white-50 small">Sube el CSV del proveedor. El archivo debe tener encabezados en la primera fila.</p>
          <input type="file" id="csvFile" accept=".csv,.txt" class="form-control bg-dark text-white border-secondary mb-3">
          <button class="btn fw-bold" style="background:#d4537e;color:#fff" onclick="parseCsv()">Previsualizar</button>
        </div>
        <!-- Step 2: Map columns -->
        <div id="csvStep2" class="d-none">
          <p class="text-white-50 small mb-3">Mapea cada columna del CSV al campo correspondiente:</p>
          <div class="row g-2" id="columnMapper"></div>
          <hr class="border-secondary">
          <p class="text-white-50 small">Primeras 3 filas:</p>
          <div class="table-responsive"><table class="table table-sm table-dark" id="previewTable"></table></div>
          <div id="csvImportError" class="alert alert-danger py-2 d-none mt-2"></div>
          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-secondary" onclick="csvReset()">← Atrás</button>
            <button class="btn fw-bold" style="background:#d4537e;color:#fff" onclick="doImport()">Importar</button>
          </div>
        </div>
        <!-- Step 3: Result -->
        <div id="csvStep3" class="d-none text-center py-3">
          <i class="bi bi-check-circle-fill text-success fs-1 d-block mb-3"></i>
          <div id="csvResult"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Stream Result -->
<div class="modal fade" id="modalStreamResult" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="bi bi-camera-video me-2"></i>Productos generados</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="streamResult"></div>
      <div class="modal-footer border-secondary">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <a href="/products" class="btn fw-bold" style="background:#d4537e;color:#fff">Ver Productos</a>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Quick Supplier -->
<div class="modal fade" id="modalQuickSupplier" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-sm">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-secondary">
        <h6 class="modal-title">Nuevo proveedor</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Nombre <span class="text-danger">*</span></label>
        <input type="text" id="qsName" class="form-control bg-dark text-white border-secondary" placeholder="ej. Beauty Wholesale Inc.">
        <div id="qsError" class="alert alert-danger py-2 mt-2 d-none"></div>
      </div>
      <div class="modal-footer border-secondary">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-sm fw-bold" style="background:#d4537e;color:#fff" onclick="saveQuickSupplier()">Crear</button>
      </div>
    </div>
  </div>
</div>

<script>
var _batchModal = null, _csvModal = null, _qsModal = null;
var _activeBatch = null;
var _csvHeaders  = [], _csvRows = [];
var _csvBatchId  = null;

var FIELDS = [
  { value: '', label: '— Ignorar —' },
  { value: 'description',         label: 'Descripción *' },
  { value: 'qty',                 label: 'Cantidad *' },
  { value: 'unit_cost_usd',       label: 'Costo unitario USD *' },
  { value: 'brand',               label: 'Marca' },
  { value: 'upc',                 label: 'UPC' },
  { value: 'supplier_product_id', label: 'ID Proveedor' },
  { value: 'color',               label: 'Color' },
  { value: 'size',                label: 'Talla/Tamaño' },
  { value: 'packaging_type',      label: 'Empaque' },
];

var AUTO_MAP = {
  'description': ['description','descripcion','desc','item','product','nombre','name'],
  'qty':         ['qty','quantity','cantidad','units','unidades','pcs','pieces'],
  'unit_cost_usd': ['cost','costo','price','precio','unit cost','unit price','unit_cost','price_each'],
  'brand':       ['brand','marca'],
  'upc':         ['upc','barcode','codigo','code','sku'],
  'supplier_product_id': ['item #','item#','item no','part no','part#','id'],
  'color':       ['color','colour'],
  'size':        ['size','talla','tamaño','oz','ml'],
  'packaging_type': ['packaging','type','tipo','pack'],
};

var STATUS_LABELS = { PENDING:'Pendiente', RECEIVED:'Recibido', PARTIAL:'Parcial' };
var INV_LABELS = { JACK:'Jack', NESIM:'Nesim', ALEX:'Alex', SIMON:'Simon', COMPARTIDO:'Compartido' };
var INV_COLORS = { JACK:'warning', NESIM:'info', ALEX:'primary', SIMON:'success', COMPARTIDO:'secondary' };

document.addEventListener('DOMContentLoaded', function() {
    _batchModal = new bootstrap.Modal(document.getElementById('modalBatch'));
    _csvModal   = new bootstrap.Modal(document.getElementById('modalCsv'));
    _qsModal    = new bootstrap.Modal(document.getElementById('modalQuickSupplier'));
    loadBatches();
});

function loadBatches() {
    apiFetch('?action=list').then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        renderBatchList(d.data);
    });
}

function renderBatchList(batches) {
    var el = document.getElementById('batchList');
    if (!batches.length) { el.innerHTML = '<div class="list-group-item text-muted text-center py-3">Sin lotes registrados.</div>'; return; }
    el.innerHTML = batches.map(function(b) {
        var active = _activeBatch === b.id ? 'active' : '';
        return '<a href="#" class="list-group-item list-group-item-action ' + active + '" onclick="loadDetail(' + b.id + ');return false;">'
            + '<div class="d-flex justify-content-between">'
            + '<span class="fw-semibold small">' + esc(b.reference_no) + '</span>'
            + '<span class="badge bg-' + INV_COLORS[b.investor] + '">' + INV_LABELS[b.investor] + '</span>'
            + '</div>'
            + '<div class="text-muted" style="font-size:.75rem">' + esc(b.supplier_name) + ' · ' + b.batch_date + '</div>'
            + '<div class="d-flex justify-content-between mt-1">'
            + '<span class="text-muted" style="font-size:.75rem">' + fmtN(b.unit_count) + ' uds · ' + b.item_count + ' SKUs</span>'
            + '<span class="fw-bold small">$' + parseFloat(b.total_usd).toLocaleString('es-MX', {minimumFractionDigits:2,maximumFractionDigits:2}) + '</span>'
            + '</div>'
            + '</a>';
    }).join('');
}

function loadDetail(batchId) {
    _activeBatch = batchId;
    document.getElementById('detailPanel').innerHTML = '<div class="card shadow-sm"><div class="card-body text-muted text-center py-4">Cargando…</div></div>';
    Promise.all([
        apiFetch('?action=get&id=' + batchId),
        apiFetch('?action=items&id=' + batchId),
        apiFetch('?action=batch_pnl&id=' + batchId)
    ]).then(function(results) {
        var batch = results[0].data, items = results[1].data;
        renderDetail(batch, items);
        renderPnl(results[2]);
        loadBatches(); // refresh active state
    });
}

function renderDetail(b, items) {
    var totalUnits = items.reduce(function(a,i){return a+parseInt(i.qty);},0);
    var totalCost  = items.reduce(function(a,i){return a+(parseFloat(i.qty)*parseFloat(i.unit_cost_usd));},0);

    var rows = items.length ? items.map(function(item) {
        return '<tr>'
            + '<td class="text-muted small">' + esc(item.supplier_product_id||'—') + '</td>'
            + '<td class="fw-semibold">' + esc(item.brand||'—') + '</td>'
            + '<td>' + esc(item.description) + '</td>'
            + '<td class="font-monospace small">' + esc(item.upc||'—') + '</td>'
            + '<td>' + esc(item.color||'—') + '</td>'
            + '<td>' + esc(item.size||'—') + '</td>'
            + '<td class="fw-bold">' + fmtN(item.qty) + '</td>'
            + '<td class="text-warning">' + fmt2(item.unit_cost_usd) + '</td>'
            + '<td>' + fmt2(parseFloat(item.qty)*parseFloat(item.unit_cost_usd)) + '</td>'
            + '</tr>';
    }).join('') : '<tr><td colspan="9" class="text-center text-muted py-3">Sin ítems. Importa un CSV.</td></tr>';

    document.getElementById('detailPanel').innerHTML = ''
        + '<div class="card shadow-sm mb-3">'
        + '<div class="card-header d-flex justify-content-between align-items-start">'
        + '<div>'
        + '<div class="fw-bold">' + esc(b.reference_no) + ' · <span class="text-muted">' + esc(b.supplier_name) + '</span></div>'
        + '<div class="text-muted small">' + b.batch_date + ' · <span class="badge bg-' + INV_COLORS[b.investor] + '">' + INV_LABELS[b.investor] + '</span>'
        + ' · <span class="badge bg-secondary">' + STATUS_LABELS[b.status] + '</span></div>'
        + '</div>'
        + '<div class="d-flex gap-2">'
        + '<button class="btn btn-sm btn-outline-secondary" onclick="openEdit(' + b.id + ')"><i class="bi bi-pencil"></i></button>'
        + '<button class="btn btn-sm btn-outline-primary" onclick="openCsvImport(' + b.id + ',\'' + esc(b.reference_no) + '\')"><i class="bi bi-file-earmark-arrow-up me-1"></i>CSV</button>'
        + '<button class="btn btn-sm btn-outline-success" onclick="generateProducts(' + b.id + ')"><i class="bi bi-magic me-1"></i>Generar Productos</button>'
        + '</div>'
        + '</div>'
        + '<div class="card-body py-2">'
        + '<div class="row text-center g-0">'
        + '<div class="col-4 border-end"><div class="fw-bold text-primary">' + fmtN(items.length) + '</div><div class="text-muted small">SKUs</div></div>'
        + '<div class="col-4 border-end"><div class="fw-bold text-success">' + fmtN(totalUnits) + '</div><div class="text-muted small">Unidades</div></div>'
        + '<div class="col-4"><div class="fw-bold text-warning">' + fmt2(totalCost) + '</div><div class="text-muted small">Total USD</div></div>'
        + '</div>'
        + '</div>'
        + '</div>'
        + '<div class="card shadow-sm">'
        + '<div class="card-body p-0">'
        + '<table class="table table-sm table-hover mb-0">'
        + '<thead class="table-dark"><tr><th>ID Prov.</th><th>Marca</th><th>Descripción</th><th>UPC</th><th>Color</th><th>Talla</th><th>Qty</th><th>Costo u.</th><th>Total</th></tr></thead>'
        + '<tbody>' + rows + '</tbody>'
        + (items.length ? '<tfoot class="table-secondary"><tr><td colspan="6" class="text-end fw-bold">Total</td><td class="fw-bold">' + fmtN(totalUnits) + '</td><td></td><td class="fw-bold text-warning">' + fmt2(totalCost) + '</td></tr></tfoot>' : '')
        + '</table>'
        + '</div>'
        + '</div>';
}

// ── Batch CRUD ──
function openCreate() {
    document.getElementById('batchModalTitle').textContent = 'Nuevo lote de compra';
    document.getElementById('bId').value = '';
    document.getElementById('bSupplier').value = '';
    document.getElementById('bRef').value = '';
    document.getElementById('bDate').value = new Date().toISOString().slice(0,10);
    document.getElementById('bTotal').value = '';
    document.getElementById('bInvestor').value = 'JACK';
    document.getElementById('bStatus').value = 'PENDING';
    document.getElementById('bNotes').value = '';
    document.getElementById('batchFormError').classList.add('d-none');
    _batchModal.show();
}

function openEdit(id) {
    apiFetch('?action=get&id=' + id).then(function(d) {
        if (!d.ok) { toast(d.error,'error'); return; }
        var r = d.data;
        document.getElementById('batchModalTitle').textContent = 'Editar lote';
        document.getElementById('bId').value       = r.id;
        document.getElementById('bSupplier').value = r.supplier_id;
        document.getElementById('bRef').value      = r.reference_no;
        document.getElementById('bDate').value     = r.batch_date;
        document.getElementById('bTotal').value    = r.total_usd;
        document.getElementById('bInvestor').value = r.investor;
        document.getElementById('bStatus').value   = r.status;
        document.getElementById('bNotes').value    = r.notes || '';
        document.getElementById('batchFormError').classList.add('d-none');
        _batchModal.show();
    });
}

function saveBatch() {
    var id = document.getElementById('bId').value;
    var payload = {
        id:          id ? parseInt(id) : undefined,
        supplier_id: document.getElementById('bSupplier').value,
        reference_no: document.getElementById('bRef').value.trim(),
        batch_date:  document.getElementById('bDate').value,
        total_usd:   parseFloat(document.getElementById('bTotal').value||0),
        investor:    document.getElementById('bInvestor').value,
        status:      document.getElementById('bStatus').value,
        notes:       document.getElementById('bNotes').value.trim(),
    };
    if (!payload.supplier_id || !payload.reference_no || !payload.batch_date) {
        document.getElementById('batchFormError').textContent = 'Proveedor, referencia y fecha son requeridos.';
        document.getElementById('batchFormError').classList.remove('d-none'); return;
    }
    apiFetch('?action=' + (id ? 'update' : 'create'), { body: payload }).then(function(d) {
        if (!d.ok) { document.getElementById('batchFormError').textContent = d.error; document.getElementById('batchFormError').classList.remove('d-none'); return; }
        _batchModal.hide();
        toast(id ? 'Lote actualizado.' : 'Lote creado.');
        loadBatches();
        if (d.data && d.data.id) loadDetail(d.data.id);
        else if (id) loadDetail(parseInt(id));
    });
}

// ── CSV Import ──
function openCsvImport(batchId, batchName) {
    _csvBatchId = batchId;
    document.getElementById('csvBatchName').textContent = batchName;
    csvReset();
    _csvModal.show();
}

function csvReset() {
    document.getElementById('csvStep1').classList.remove('d-none');
    document.getElementById('csvStep2').classList.add('d-none');
    document.getElementById('csvStep3').classList.add('d-none');
    document.getElementById('csvFile').value = '';
    _csvHeaders = []; _csvRows = [];
}

function parseCsv() {
    var file = document.getElementById('csvFile').files[0];
    if (!file) { toast('Selecciona un archivo CSV.', 'error'); return; }
    var reader = new FileReader();
    reader.onload = function(e) {
        var lines = e.target.result.split(/\r?\n/).filter(function(l){return l.trim();});
        if (lines.length < 2) { toast('CSV vacío o sin datos.', 'error'); return; }
        _csvHeaders = parseCsvLine(lines[0]);
        _csvRows    = lines.slice(1, 4).map(parseCsvLine);
        renderMapper();
        document.getElementById('csvStep1').classList.add('d-none');
        document.getElementById('csvStep2').classList.remove('d-none');
    };
    reader.readAsText(file);
}

function parseCsvLine(line) {
    var result = [], current = '', inQ = false;
    for (var i = 0; i < line.length; i++) {
        if (line[i] === '"') { inQ = !inQ; }
        else if (line[i] === ',' && !inQ) { result.push(current.trim()); current = ''; }
        else { current += line[i]; }
    }
    result.push(current.trim());
    return result;
}

function autoDetect(header) {
    var h = header.toLowerCase().trim();
    for (var field in AUTO_MAP) {
        if (AUTO_MAP[field].some(function(k){return h.includes(k);})) return field;
    }
    return '';
}

function renderMapper() {
    var mapper = document.getElementById('columnMapper');
    mapper.innerHTML = _csvHeaders.map(function(h, i) {
        var detected = autoDetect(h);
        var opts = FIELDS.map(function(f) {
            return '<option value="' + f.value + '"' + (f.value === detected ? ' selected' : '') + '>' + f.label + '</option>';
        }).join('');
        return '<div class="col-md-3 col-6">'
            + '<label class="form-label text-white-50 small">' + esc(h) + '</label>'
            + '<select class="form-select form-select-sm bg-dark text-white border-secondary col-map" data-col="' + i + '">' + opts + '</select>'
            + '</div>';
    }).join('');

    // Preview table
    var tbl = document.getElementById('previewTable');
    tbl.innerHTML = '<thead><tr>' + _csvHeaders.map(function(h){return '<th class="small">'+esc(h)+'</th>';}).join('') + '</tr></thead>'
        + '<tbody>' + _csvRows.map(function(row){ return '<tr>' + row.map(function(c){return '<td class="small">'+esc(c)+'</td>';}).join('') + '</tr>'; }).join('') + '</tbody>';
}

function doImport() {
    var mapping = {};
    document.querySelectorAll('.col-map').forEach(function(sel) {
        if (sel.value) mapping[sel.dataset.col] = sel.value;
    });
    var required = ['description','qty','unit_cost_usd'];
    var missing  = required.filter(function(f){ return !Object.values(mapping).includes(f); });
    if (missing.length) {
        document.getElementById('csvImportError').textContent = 'Mapea los campos requeridos: ' + missing.join(', ');
        document.getElementById('csvImportError').classList.remove('d-none'); return;
    }
    document.getElementById('csvImportError').classList.add('d-none');

    var formData = new FormData();
    formData.append('csrf_token', window.CSRF_TOKEN);
    formData.append('batch_id', _csvBatchId);
    formData.append('mapping', JSON.stringify(mapping));
    formData.append('csv_file', document.getElementById('csvFile').files[0]);

    fetch('?action=import_csv', { method: 'POST', body: formData })
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (!d.ok) { document.getElementById('csvImportError').textContent = d.error; document.getElementById('csvImportError').classList.remove('d-none'); return; }
            document.getElementById('csvStep2').classList.add('d-none');
            document.getElementById('csvStep3').classList.remove('d-none');
            var res = '<p class="fw-bold fs-5 text-success">' + fmtN(d.data.inserted) + ' ítems importados</p>';
            if (d.data.skipped) res += '<p class="text-muted">' + d.data.skipped + ' filas saltadas</p>';
            if (d.data.errors && d.data.errors.length) res += '<ul class="text-warning small text-start">' + d.data.errors.map(function(e){return '<li>'+esc(e)+'</li>';}).join('') + '</ul>';
            res += '<button class="btn btn-outline-secondary btn-sm mt-2" onclick="' + "document.getElementById('modalCsv').querySelector('.btn-close').click()" + '">Cerrar</button>';
            document.getElementById('csvResult').innerHTML = res;
            loadDetail(_csvBatchId);
        });
}

// ── Quick Supplier ──
function openQuickSupplier() {
    document.getElementById('qsName').value = '';
    document.getElementById('qsError').classList.add('d-none');
    _qsModal.show();
}

function saveQuickSupplier() {
    var name = document.getElementById('qsName').value.trim();
    if (!name) { document.getElementById('qsError').textContent = 'Nombre requerido.'; document.getElementById('qsError').classList.remove('d-none'); return; }
    apiFetch('?action=quick_supplier', { body: { name: name } }).then(function(d) {
        if (!d.ok) { document.getElementById('qsError').textContent = d.error; document.getElementById('qsError').classList.remove('d-none'); return; }
        var opt = new Option(d.data.name, d.data.id);
        document.getElementById('bSupplier').appendChild(opt);
        document.getElementById('bSupplier').value = d.data.id;
        _qsModal.hide();
        toast('Proveedor "' + d.data.name + '" creado.');
    });
}

// ── Batch P&L ──
function renderPnl(pnl) {
    if (!pnl || !pnl.ok) return;
    var d = pnl.data;
    var panel = document.getElementById('detailPanel');
    if (!d.has_products) {
        panel.insertAdjacentHTML('beforeend',
            '<div class="card shadow-sm mt-3"><div class="card-body text-center text-muted py-3 small">'
            + '<i class="bi bi-bar-chart me-1"></i>Genera productos para ver el análisis P&L'
            + '</div></div>'
        );
        return;
    }
    var rows = d.groups.map(function(g) {
        var hi = g.cost_usd >= 1.00;
        return '<tr' + (hi ? ' style="border-top:2px solid #6c757d"' : '') + '>'
            + '<td class="fw-semibold">' + fmt2(g.cost_usd) + '</td>'
            + '<td class="text-warning fw-bold">' + fmt2(g.rescue_price) + '</td>'
            + '<td class="text-end">' + fmtN(g.units) + '</td>'
            + '<td class="text-end">' + fmt2(g.gross_revenue) + '</td>'
            + '<td class="text-end text-danger">' + fmt2(g.fees) + '</td>'
            + '<td class="text-end">' + fmt2(g.net_seller) + '</td>'
            + '<td class="text-end text-warning">' + fmt2(g.cogs) + '</td>'
            + '<td class="text-end fw-bold ' + (g.gross_profit >= 0 ? 'text-success' : 'text-danger') + '">' + fmt2(g.gross_profit) + '</td>'
            + '</tr>';
    }).join('');
    var t = d.totals;
    var nc = t.net_profit >= 0 ? 'text-success' : 'text-danger';
    panel.insertAdjacentHTML('beforeend',
        '<div class="card shadow-sm mt-3">'
        + '<div class="card-header d-flex align-items-center gap-2">'
        + '<i class="bi bi-bar-chart-fill text-info"></i>'
        + '<span class="fw-bold">Análisis P&L — A precio rescue</span>'
        + '<span class="badge bg-secondary ms-auto">' + fmtN(t.units) + ' uds</span>'
        + '</div>'
        + '<div class="card-body p-0"><div class="table-responsive">'
        + '<table class="table table-sm table-hover mb-0">'
        + '<thead class="table-dark"><tr>'
        + '<th>Costo</th><th>Rescue</th><th class="text-end">Uds</th>'
        + '<th class="text-end">Gross Whatnot</th><th class="text-end">Fees Whatnot</th>'
        + '<th class="text-end">Net Seller</th><th class="text-end">Costo Inv.</th>'
        + '<th class="text-end">Profit</th>'
        + '</tr></thead>'
        + '<tbody>' + rows + '</tbody>'
        + '<tfoot>'
        + '<tr class="table-secondary fw-bold">'
        + '<td colspan="2">TOTAL</td>'
        + '<td class="text-end">' + fmtN(t.units) + '</td>'
        + '<td class="text-end">' + fmt2(t.gross_revenue) + '</td>'
        + '<td class="text-end text-danger">' + fmt2(t.fees) + '</td>'
        + '<td class="text-end">' + fmt2(t.net_seller) + '</td>'
        + '<td class="text-end text-warning">' + fmt2(t.cogs) + '</td>'
        + '<td class="text-end text-success">' + fmt2(t.gross_profit) + '</td>'
        + '</tr>'
        + '<tr class="table-dark text-muted small">'
        + '<td colspan="7" class="text-end">Costo streamer (' + t.stream_hours + 'h × ' + fmt2(t.streamer_hourly) + '/hr)</td>'
        + '<td class="text-end text-danger fw-bold">' + fmt2(-t.stream_cost) + '</td>'
        + '</tr>'
        + '<tr class="table-dark">'
        + '<td colspan="7" class="text-end fw-bold">NET PROFIT</td>'
        + '<td class="text-end fw-bold fs-6 ' + nc + '">' + fmt2(t.net_profit) + '</td>'
        + '</tr>'
        + '</tfoot>'
        + '</table>'
        + '</div></div>'
        + '</div>'
    );
}

// ── Generate Products ──
function generateProducts(batchId) {
    if (!confirm('¿Generar productos en el catálogo para este lote?\nSe calcularán costos y precios rescue automáticamente.')) return;
    apiFetch('?action=generate_products&batch_id=' + batchId, { method: 'POST' }).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        var r = d.data;
        var shows2h = Math.ceil(r.stream_hours / 2);
        var shows3h = Math.ceil(r.stream_hours / 3);
        var goalBar = Math.min(100, r.goal_pct);
        document.getElementById('streamResult').innerHTML =
            '<div class="alert alert-success mb-0 py-2">'
            + '<strong>' + fmtN(r.created) + ' productos generados</strong>'
            + (r.skipped ? ' <span class="text-muted small">(' + r.skipped + ' ya existían)</span>' : '')
            + '</div>'
            + '<div class="card bg-dark border-secondary mt-3"><div class="card-body">'
            + '<div class="fw-bold mb-3" style="color:#d4537e"><i class="bi bi-camera-video me-2"></i>Streaming Plan — Este lote</div>'
            + '<div class="row text-center g-2 mb-3">'
            + '<div class="col-3"><div class="fw-bold text-warning">' + fmtN(r.stream_skus) + '</div><div class="text-muted" style="font-size:.75rem">SKUs</div></div>'
            + '<div class="col-3"><div class="fw-bold text-info">' + fmtN(r.stream_units) + '</div><div class="text-muted" style="font-size:.75rem">Unidades</div></div>'
            + '<div class="col-3"><div class="fw-bold">' + r.stream_hours + 'h</div><div class="text-muted" style="font-size:.75rem">de stream</div></div>'
            + '<div class="col-3"><div class="fw-bold">' + shows2h + '/' + shows3h + '</div><div class="text-muted" style="font-size:.75rem">shows 2h/3h</div></div>'
            + '</div>'
            + '<div class="d-flex justify-content-between small mb-1"><span class="text-muted">Costo streamer (' + fmt2(r.stream_cost / r.stream_hours) + '/hr)</span><span class="text-danger fw-bold">' + fmt2(r.stream_cost) + '</span></div>'
            + '<hr class="border-secondary my-2">'
            + '<div class="fw-semibold mb-2 small" style="color:#d4537e"><i class="bi bi-bullseye me-1"></i>Contribución a meta $' + fmtN(r.goal) + '</div>'
            + '<div class="d-flex justify-content-between small mb-1"><span class="text-muted">Precio rescue promedio</span><span class="fw-bold">' + fmt2(r.avg_rescue) + '</span></div>'
            + '<div class="d-flex justify-content-between small mb-2"><span class="text-muted">Revenue potencial de este lote</span><span class="fw-bold text-success">' + fmt2(r.potential_rev) + '</span></div>'
            + '<div class="progress mb-1" style="height:8px"><div class="progress-bar bg-success" style="width:' + goalBar + '%"></div></div>'
            + '<div class="text-muted text-end" style="font-size:.72rem">' + r.goal_pct + '% de la meta</div>'
            + '</div></div>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalStreamResult')).show();
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
