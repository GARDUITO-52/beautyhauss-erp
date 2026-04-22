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
                   GROUP_CONCAT(h.name ORDER BY h.name SEPARATOR ' + ') AS host_name
            FROM shows s
            LEFT JOIN show_hosts sh ON sh.show_id = s.id
            LEFT JOIN hosts h ON h.id = sh.host_id
            $where GROUP BY s.id ORDER BY s.scheduled_at DESC LIMIT 100");
        $rows->execute($params);
        jsonOk($rows->fetchAll());
    }

    if ($action === 'lineup') {
        $show_id = (int)($_GET['show_id'] ?? 0);
        if (!$show_id) jsonErr('show_id requerido.');
        $show = $pdo->prepare("SELECT s.id, s.title, s.scheduled_at, s.status, GROUP_CONCAT(h.name ORDER BY h.name SEPARATOR ' + ') AS host FROM shows s LEFT JOIN show_hosts sh ON sh.show_id=s.id LEFT JOIN hosts h ON h.id=sh.host_id WHERE s.id=? GROUP BY s.id");
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

    if ($action === 'calendar') {
        $cfg = $pdo->query("SELECT config_key, config_value FROM system_config")->fetchAll(PDO::FETCH_KEY_PAIR);
        $start    = $cfg['goal_start_date'] ?? date('Y-m-d');
        $end      = $cfg['goal_end_date'] ?? date('Y-m-d', strtotime('+30 days'));
        $show_dur = (float)($cfg['show_duration_hrs'] ?? 4);
        $rows = $pdo->prepare("
            SELECT s.id, s.title, s.scheduled_at, s.status, s.estimated_duration_hrs,
                   GROUP_CONCAT(h.id        ORDER BY h.name SEPARATOR ',') AS host_ids,
                   GROUP_CONCAT(h.name      ORDER BY h.name SEPARATOR ',') AS host_names,
                   GROUP_CONCAT(CAST(h.hourly_rate_usd AS CHAR) ORDER BY h.name SEPARATOR ',') AS host_rates
            FROM shows s
            LEFT JOIN show_hosts sh ON sh.show_id = s.id
            LEFT JOIN hosts h ON h.id = sh.host_id
            WHERE DATE(s.scheduled_at) BETWEEN ? AND ?
            GROUP BY s.id ORDER BY s.scheduled_at ASC");
        $rows->execute([$start, $end]);
        $hosts_cal = $pdo->query("SELECT id, name, hourly_rate_usd FROM hosts WHERE is_active=1 ORDER BY name")->fetchAll();
        $avail     = $pdo->query("SELECT host_id, day_of_week, start_time, end_time FROM host_availability")->fetchAll();
        jsonOk(['shows' => $rows->fetchAll(), 'period' => ['start' => $start, 'end' => $end], 'show_dur' => $show_dur, 'hosts' => $hosts_cal, 'availability' => $avail]);
    }

    if ($action === 'search_products') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { jsonOk([]); }
        $like = '%' . $q . '%';
        $rows = $pdo->prepare("
            SELECT id, sku_internal, brand, description, color, size, stock_qty, rescue_price_usd
            FROM products
            WHERE stock_qty > 0 AND (brand LIKE ? OR description LIKE ? OR sku_internal LIKE ? OR upc LIKE ?)
            ORDER BY brand, description LIMIT 20");
        $rows->execute([$like, $like, $like, $like]);
        jsonOk($rows->fetchAll());
    }

    function overlapCheck(PDO $pdo, array $host_ids, int $exclude_show, string $scheduled_at, float $dur): void {
        if (!$host_ids) return;
        $placeholders = implode(',', array_fill(0, count($host_ids), '?'));
        $params = array_merge($host_ids, [$exclude_show, $scheduled_at, $dur, $scheduled_at]);
        $stmt = $pdo->prepare("
            SELECT h.name FROM shows s
            JOIN show_hosts sh ON sh.show_id = s.id
            JOIN hosts h ON h.id = sh.host_id
            WHERE sh.host_id IN ($placeholders)
              AND s.id != ?
              AND s.scheduled_at < DATE_ADD(?, INTERVAL ? HOUR)
              AND DATE_ADD(s.scheduled_at, INTERVAL s.estimated_duration_hrs HOUR) > ?
            LIMIT 1");
        $stmt->execute($params);
        $conflict = $stmt->fetchColumn();
        if ($conflict) jsonErr($conflict . ' ya tiene un show en ese horario.');
    }

    if ($is_admin) {
        if ($action === 'get') {
            $id  = (int)($_GET['id'] ?? 0);
            $row = $pdo->prepare("
                SELECT s.*,
                       GROUP_CONCAT(sh.host_id ORDER BY sh.host_id SEPARATOR ',') AS host_ids
                FROM shows s
                LEFT JOIN show_hosts sh ON sh.show_id = s.id
                WHERE s.id=? GROUP BY s.id");
            $row->execute([$id]); $row = $row->fetch();
            if (!$row) jsonErr('No encontrado.', 404);
            jsonOk($row);
        }
        if ($action === 'add_product') {
            csrfGuard();
            $d          = json_decode(file_get_contents('php://input'), true) ?? [];
            $show_id    = (int)($d['show_id'] ?? 0);
            $product_id = (int)($d['product_id'] ?? 0);
            $qty        = (int)($d['qty_listed'] ?? 0);
            $bid        = (float)($d['starting_bid_usd'] ?? 0);
            if (!$show_id || !$product_id || $qty < 1) jsonErr('Datos inválidos.');
            $dup = $pdo->prepare("SELECT id FROM show_products WHERE show_id=? AND product_id=?");
            $dup->execute([$show_id, $product_id]);
            if ($dup->fetch()) jsonErr('Este producto ya está en el lineup.');
            $pdo->prepare("INSERT INTO show_products (show_id, product_id, qty_listed, starting_bid_usd) VALUES (?,?,?,?)")
                ->execute([$show_id, $product_id, $qty, $bid]);
            jsonOk(['id' => $pdo->lastInsertId()]);
        }
        if ($action === 'remove_product') {
            csrfGuard();
            $d       = json_decode(file_get_contents('php://input'), true) ?? [];
            $sp_id   = (int)($d['id'] ?? 0);
            $show_id = (int)($d['show_id'] ?? 0);
            if (!$sp_id || !$show_id) jsonErr('Datos inválidos.');
            $pdo->prepare("DELETE FROM show_products WHERE id=? AND show_id=?")->execute([$sp_id, $show_id]);
            jsonOk();
        }
        if ($action === 'bulk_add_to_show') {
            csrfGuard();
            $d       = json_decode(file_get_contents('php://input'), true) ?? [];
            $show_id = (int)($d['show_id'] ?? 0);
            $items   = $d['products'] ?? [];
            if (!$show_id || !is_array($items) || !$items) jsonErr('Datos inválidos.');
            $chk = $pdo->prepare("SELECT id FROM shows WHERE id = ?");
            $chk->execute([$show_id]);
            if (!$chk->fetch()) jsonErr('Show no encontrado.', 404);
            $existing = $pdo->prepare("SELECT product_id FROM show_products WHERE show_id = ?");
            $existing->execute([$show_id]);
            $existingSet = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));
            $stmt  = $pdo->prepare("INSERT INTO show_products (show_id, product_id, qty_listed, starting_bid_usd) VALUES (?,?,?,?)");
            $added = 0; $skipped = 0;
            foreach ($items as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $qty = (int)($item['qty_listed'] ?? 0);
                $bid = (float)($item['starting_bid_usd'] ?? 0);
                if (!$pid || $qty < 1) { $skipped++; continue; }
                if (isset($existingSet[$pid])) { $skipped++; continue; }
                $stmt->execute([$show_id, $pid, $qty, $bid]);
                $existingSet[$pid] = true;
                $added++;
            }
            logActivity($pdo, 'shows', 'bulk_add', $show_id, $added . ' productos agregados al show ' . $show_id);
            jsonOk(['added' => $added, 'skipped' => $skipped]);
        }
        if ($action === 'create') {
            csrfGuard();
            $d = json_decode(file_get_contents('php://input'), true) ?? [];
            $title = trim($d['title'] ?? '');
            if (!$title || empty($d['scheduled_at'])) jsonErr('Título y fecha son requeridos.');
            $host_ids = array_filter(array_map('intval', $d['host_ids'] ?? []));
            overlapCheck($pdo, $host_ids, 0, $d['scheduled_at'], $d['estimated_duration_hrs'] ?? 4);
            $pdo->prepare("INSERT INTO shows (title, scheduled_at, estimated_duration_hrs, status, notes) VALUES (?,?,?,?,?)")
                ->execute([$title, $d['scheduled_at'], $d['estimated_duration_hrs'] ?? 4, 'SCHEDULED', trim($d['notes'] ?? '')]);
            $id = $pdo->lastInsertId();
            $sh = $pdo->prepare("INSERT IGNORE INTO show_hosts (show_id, host_id) VALUES (?,?)");
            foreach ($host_ids as $hid) $sh->execute([$id, $hid]);
            logActivity($pdo, 'shows', 'create', $id, $title);
            jsonOk(['id' => $id]);
        }
        if ($action === 'update') {
            csrfGuard();
            $d  = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = (int)($d['id'] ?? 0);
            $title = trim($d['title'] ?? '');
            if (!$id || !$title) jsonErr('Datos inválidos.');
            $host_ids = array_filter(array_map('intval', $d['host_ids'] ?? []));
            $pdo->prepare("UPDATE shows SET title=?, scheduled_at=?, estimated_duration_hrs=?, status=?, notes=? WHERE id=?")
                ->execute([$title, $d['scheduled_at'], $d['estimated_duration_hrs'] ?? 4,
                           $d['status'] ?? 'SCHEDULED', trim($d['notes'] ?? ''), $id]);
            overlapCheck($pdo, $host_ids, $id, $d['scheduled_at'], $d['estimated_duration_hrs'] ?? 4);
            $pdo->prepare("DELETE FROM show_hosts WHERE show_id=?")->execute([$id]);
            $sh = $pdo->prepare("INSERT IGNORE INTO show_hosts (show_id, host_id) VALUES (?,?)");
            foreach ($host_ids as $hid) $sh->execute([$id, $hid]);
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

        if ($action === 'close_show') {
            csrfGuard();
            $d         = json_decode(file_get_contents('php://input'), true) ?? [];
            $show_id   = (int)($d['show_id'] ?? 0);
            $boxes     = (int)($d['boxes_sold'] ?? 0);
            $revenue   = (float)($d['revenue_usd'] ?? 0);
            $avg_units = (float)($d['avg_units_per_box'] ?? 5.0);
            $boost     = (float)($d['community_boost_usd'] ?? 0);
            $format    = $d['format'] ?? 'pulls';
            $notes_post = trim($d['notes_post'] ?? '');
            if (!$show_id) jsonErr('show_id requerido.');

            $depleted = (int)round($boxes * $avg_units);

            // Get avg cost across all batches for COGS calculation
            $avg_cost_row = $pdo->query("
                SELECT SUM(total_usd) / NULLIF(SUM(total_qty), 0) AS global_avg
                FROM (
                    SELECT pb.total_usd, SUM(pbi.qty) AS total_qty
                    FROM purchase_batches pb
                    LEFT JOIN purchase_batch_items pbi ON pbi.batch_id = pb.id
                    GROUP BY pb.id
                ) t
            ")->fetch();
            $global_avg_cost = (float)($avg_cost_row['global_avg'] ?? 0);
            $cogs_per_order  = $global_avg_cost; // 1 unit per pull order

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE shows SET
                    status='DONE', boxes_sold=?, revenue_usd=?, avg_units_per_box=?,
                    units_depleted=?, community_boost_usd=?, format=?, notes_post=?
                    WHERE id=?")->execute([$boxes, $revenue, $avg_units, $depleted, $boost, $format, $notes_post, $show_id]);

                // Populate cogs_usd on orders for this show that have no cogs yet
                if ($global_avg_cost > 0) {
                    $pdo->prepare("UPDATE orders SET cogs_usd=? WHERE show_id=? AND cogs_usd=0")
                        ->execute([$cogs_per_order, $show_id]);
                }

                // Proportional stock decrement across all batches (makeup only for pulls format)
                if ($depleted > 0) {
                    $total_stock_row = $pdo->query("SELECT SUM(stock_qty) FROM products WHERE stock_qty > 0")->fetchColumn();
                    $total_stock = max(1, (int)$total_stock_row);
                    $stmt = $pdo->prepare("
                        UPDATE products
                        SET stock_qty = GREATEST(0, stock_qty - ROUND(? * stock_qty / ?))
                        WHERE stock_qty > 0
                    ");
                    $stmt->execute([$depleted, $total_stock]);
                }

                $pdo->commit();
            } catch (\Exception $e) {
                $pdo->rollBack();
                jsonErr('Error al cerrar show: ' . $e->getMessage());
            }

            logActivity($pdo, 'shows', 'close_show', $show_id, "$boxes pulls · \$$revenue · $depleted uds depleted");
            jsonOk(['units_depleted' => $depleted, 'cogs_per_order' => $cogs_per_order]);
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

  <ul class="nav nav-pills mb-3">
    <li class="nav-item">
      <button class="nav-link active" id="tabBtnList" onclick="switchTab('list')"><i class="bi bi-list-ul me-1"></i>Lista</button>
    </li>
    <li class="nav-item">
      <button class="nav-link" id="tabBtnCalendar" onclick="switchTab('calendar')"><i class="bi bi-calendar3 me-1"></i>Calendario</button>
    </li>
  </ul>

  <div id="tabList">
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
  </div><!-- /tabList -->

  <div id="tabCalendar" class="d-none">
    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <div class="fw-bold" id="calPeriodLabel">Cargando…</div>
          <div id="calHostBadges" class="mt-1"></div>
        </div>
        <button class="btn btn-sm btn-outline-secondary" onclick="loadCalendar()"><i class="bi bi-arrow-clockwise"></i> Recargar</button>
      </div>
      <div class="card-body p-0" style="overflow-x:auto">
        <table class="table table-bordered mb-0" id="calGrid" style="table-layout:fixed;min-width:700px">
          <thead class="table-dark">
            <tr>
              <th class="text-center">Lun</th>
              <th class="text-center">Mar</th>
              <th class="text-center">Mié</th>
              <th class="text-center">Jue</th>
              <th class="text-center">Vie</th>
              <th class="text-center">Sáb</th>
              <th class="text-center">Dom</th>
            </tr>
          </thead>
          <tbody id="calBody">
            <tr><td colspan="7" class="text-center text-muted py-4">Cargando…</td></tr>
          </tbody>
        </table>
      </div>
      <div class="card-footer p-0" id="calSummary"></div>
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
            <label class="form-label">Streamers</label>
            <div id="fHosts" class="d-flex flex-wrap gap-3 mt-1">
              <?php foreach ($hosts as $h): ?>
              <div class="form-check">
                <input class="form-check-input host-check" type="checkbox"
                       id="fHost_<?= $h['id'] ?>" value="<?= $h['id'] ?>">
                <label class="form-check-label" for="fHost_<?= $h['id'] ?>">
                  <?= htmlspecialchars($h['name']) ?>
                </label>
              </div>
              <?php endforeach ?>
              <?php if (!$hosts): ?><span class="text-muted small">Sin hosts activos.</span><?php endif ?>
            </div>
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
var _activeTab  = 'list';
var _calLoaded  = false;
var HOST_COLORS = ['#d4537e','#1D9E75','#3B82F6','#F59E0B','#8B5CF6','#EC4899'];
var _hostColorMap = {};
var _calAvailMap  = {};

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
        + '<div class="d-flex gap-2 mt-2 mt-md-0 flex-wrap">'
        + '<a href="?action=export_pre_show&show_id=' + show.id + '" class="btn btn-sm btn-outline-success" title="CSV para Daniel">'
        + '<i class="bi bi-download me-1"></i>Para Daniel</a>'
        + '<a href="?action=download_template&show_id=' + show.id + '" class="btn btn-sm btn-outline-secondary" title="Template post-show">'
        + '<i class="bi bi-file-earmark-arrow-down me-1"></i>Template post-show</a>'
        + '<button class="btn btn-sm fw-bold" style="background:#d4537e;color:#fff" onclick="toggleAddPanel()">'
        + '<i class="bi bi-plus-lg me-1"></i>Agregar</button>'
        + '</div>' : '';

    var rows = items.length ? items.map(function(item) {
        var slotCell = IS_ADMIN
            ? '<td><span class="badge bg-secondary font-monospace slot-badge" style="cursor:pointer;min-width:2.5rem" '
              + 'onclick="editSlot(this,' + item.id + ',' + show.id + ')" title="Click para editar slot">'
              + esc(item.whatnot_slot || '—') + '</span></td>'
            : '<td class="font-monospace text-muted small">' + esc(item.whatnot_slot || '—') + '</td>';
        var bidCell = IS_ADMIN ? '<td class="text-warning">' + fmt2(item.starting_bid_usd) + '</td>' : '';
        var delCell = IS_ADMIN
            ? '<td><button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeProduct(' + item.id + ',' + show.id + ')">'
              + '<i class="bi bi-trash"></i></button></td>'
            : '';
        return '<tr>'
            + slotCell
            + '<td class="fw-semibold">' + esc(item.brand || '—') + '</td>'
            + '<td>' + esc(item.description) + '</td>'
            + '<td class="font-monospace small">' + esc(item.upc || '—') + '</td>'
            + '<td>' + esc(item.color || '—') + '</td>'
            + '<td>' + esc(item.size || '—') + '</td>'
            + '<td class="fw-bold">' + item.qty_listed + '</td>'
            + bidCell
            + delCell
            + '</tr>';
    }).join('') : '<tr><td colspan="10" class="text-center text-muted py-3">Sin productos en el lineup.</td></tr>';

    var bidHeader = IS_ADMIN ? '<th>Bid Inicial</th>' : '';
    var delHeader = IS_ADMIN ? '<th></th>' : '';
    var addPanel  = IS_ADMIN ? ''
        + '<div id="addPanel" class="d-none p-3 border-top border-secondary">'
        + '<div class="input-group input-group-sm mb-2">'
        + '<input type="text" id="prodSearch" class="form-control bg-dark text-white border-secondary" placeholder="Buscar marca, descripción, SKU, UPC…" oninput="searchProducts(this.value)">'
        + '<button class="btn btn-outline-secondary" type="button" onclick="clearAddForm()">✕</button>'
        + '</div>'
        + '<div id="searchResults"></div>'
        + '<div id="addForm" class="d-none mt-2">'
        + '<input type="hidden" id="selProductId">'
        + '<div class="d-flex gap-2 align-items-center flex-wrap">'
        + '<span id="selProductLabel" class="text-info small flex-grow-1"></span>'
        + '<label class="text-muted small mb-0">Qty</label>'
        + '<input type="number" id="selQty" class="form-control form-control-sm bg-dark text-white border-secondary" style="width:75px" min="1" value="1">'
        + '<label class="text-muted small mb-0">Bid $</label>'
        + '<input type="number" id="selBid" class="form-control form-control-sm bg-dark text-white border-secondary" style="width:85px" step="0.01" min="0">'
        + '<button class="btn btn-sm btn-success" onclick="addProduct(' + show.id + ')"><i class="bi bi-check-lg me-1"></i>Agregar</button>'
        + '</div>'
        + '</div>'
        + '</div>' : '';

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
        + addPanel
        + '<div class="card-body p-0">'
        + '<table class="table table-sm mb-0">'
        + '<thead class="table-dark"><tr><th>Slot</th><th>Marca</th><th>Descripción</th><th>UPC</th><th>Color</th><th>Talla</th><th>Qty</th>' + bidHeader + delHeader + '</tr></thead>'
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

function switchTab(tab) {
    _activeTab = tab;
    document.getElementById('tabList').classList.toggle('d-none', tab !== 'list');
    document.getElementById('tabCalendar').classList.toggle('d-none', tab !== 'calendar');
    document.getElementById('tabBtnList').classList.toggle('active', tab === 'list');
    document.getElementById('tabBtnCalendar').classList.toggle('active', tab === 'calendar');
    if (tab === 'calendar' && !_calLoaded) { loadCalendar(); _calLoaded = true; }
}

function loadCalendar() {
    document.getElementById('calBody').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Cargando…</td></tr>';
    apiFetch('?action=calendar').then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        renderCalendar(d.data);
    });
}

function renderCalendar(data) {
    var startStr = data.period.start, endStr = data.period.end;
    var start    = new Date(startStr + 'T00:00:00');
    var end      = new Date(endStr   + 'T00:00:00');
    var showDur  = data.show_dur || 4;
    var maxPerDay = Math.min(3, Math.floor(12 / showDur));

    var timeSlots = [];
    for (var i = 0; i < maxPerDay; i++) {
        var h = 8 + i * showDur;
        timeSlots.push((h < 10 ? '0' : '') + h + ':00');
    }

    _hostColorMap = {};
    (data.hosts || []).forEach(function(h, i) { _hostColorMap[h.id] = HOST_COLORS[i % HOST_COLORS.length]; });

    _calAvailMap = {};
    (data.availability || []).forEach(function(a) {
        if (!_calAvailMap[a.host_id]) _calAvailMap[a.host_id] = {};
        _calAvailMap[a.host_id][a.day_of_week] = a;
    });

    var byDate = {};
    data.shows.forEach(function(s) {
        var key = s.scheduled_at.substr(0, 10);
        if (!byDate[key]) byDate[key] = [];
        byDate[key].push(s);
    });

    document.getElementById('calPeriodLabel').textContent =
        start.toLocaleDateString('es-MX', {day:'numeric', month:'long', year:'numeric'})
        + ' – ' + end.toLocaleDateString('es-MX', {day:'numeric', month:'long', year:'numeric'});

    document.getElementById('calHostBadges').innerHTML = (data.hosts||[]).map(function(h, i) {
        return '<span class="badge me-1" style="background:' + HOST_COLORS[i % HOST_COLORS.length] + '">' + esc(h.name) + '</span>';
    }).join('');

    var todayStr = new Date().toISOString().substr(0, 10);
    var cur = new Date(start);
    var dow = cur.getDay();
    cur.setDate(cur.getDate() - (dow === 0 ? 6 : dow - 1)); // back to Monday

    var gridEnd = new Date(end);
    var dowE = gridEnd.getDay();
    gridEnd.setDate(gridEnd.getDate() + (dowE === 0 ? 0 : 7 - dowE)); // forward to Sunday

    var html = '';
    while (cur <= gridEnd) {
        html += '<tr>';
        for (var d = 0; d < 7; d++) {
            var ds   = cur.getFullYear() + '-' + String(cur.getMonth()+1).padStart(2,'0') + '-' + String(cur.getDate()).padStart(2,'0');
            var inPeriod  = ds >= startStr && ds <= endStr;
            var dayOfWeek = cur.getDay();
            var isFri = dayOfWeek === 5, isSat = dayOfWeek === 6;
            var dayShows  = byDate[ds] || [];
            var isToday   = ds === todayStr;

            var bg = !inPeriod ? 'background:#1c1c1c;opacity:.5' : '';
            var outline = isToday ? 'outline:2px solid #d4537e;outline-offset:-2px;' : '';

            var numHtml = '<div class="d-flex justify-content-between align-items-center mb-1">'
                + '<span class="small fw-bold ' + (isToday ? 'text-danger' : 'text-muted') + '">' + cur.getDate() + '</span>'
                + '</div>';

            var blocks = dayShows.map(function(s) {
                var firstId = s.host_ids ? parseInt(s.host_ids.split(',')[0]) : null;
                var col     = firstId ? (_hostColorMap[firstId] || '#888') : '#888';
                var label   = s.host_names ? s.host_names.split(',').join(' + ') : '—';
                var lbl = STATUS_LABELS[s.status] || s.status;
                return '<div class="rounded px-1 mb-1 text-white text-truncate" style="background:' + col + ';font-size:.65rem;cursor:pointer;line-height:1.6"'
                    + ' onclick="switchTab(\'list\');loadLineup(' + s.id + ')" title="' + esc(s.title) + ' — ' + lbl + '">'
                    + s.scheduled_at.substr(11,5) + ' ' + esc(label)
                    + '</div>';
            }).join('');

            var isoDow = dayOfWeek === 0 ? 7 : dayOfWeek;
            var availDots = inPeriod ? (data.hosts || []).map(function(h) {
                var a = _calAvailMap[h.id] && _calAvailMap[h.id][isoDow];
                if (!a) return '';
                return '<span title="' + esc(h.name) + ' ' + a.start_time.substr(0,5) + '–' + a.end_time.substr(0,5) + '" '
                    + 'style="display:inline-block;width:7px;height:7px;border-radius:50%;background:' + (_hostColorMap[h.id]||'#888') + ';margin:0 1px;opacity:.85"></span>';
            }).join('') : '';

            var addBtn = (inPeriod && !(isSat) && IS_ADMIN && dayShows.length < maxPerDay)
                ? '<button class="btn w-100 mt-1 py-0 text-muted" style="font-size:.65rem;border:1px dashed #444" '
                  + 'onclick="openCreate(\'' + ds + '\',\'' + getNextSlot(dayShows, timeSlots) + '\')">+ show</button>'
                : '';

            html += '<td style="vertical-align:top;min-width:90px;padding:4px;' + bg + outline + '">'
                + numHtml + (availDots ? '<div class="mb-1">' + availDots + '</div>' : '') + blocks + addBtn + '</td>';

            cur.setDate(cur.getDate() + 1);
        }
        html += '</tr>';
    }

    document.getElementById('calBody').innerHTML = html;
    renderEarningsSummary(data);
}

function getNextSlot(dayShows, slots) {
    var used = dayShows.map(function(s) { return s.scheduled_at.substr(11, 5); });
    for (var i = 0; i < slots.length; i++) {
        if (used.indexOf(slots[i]) < 0) return slots[i];
    }
    return slots[0] || '08:00';
}

function renderEarningsSummary(data) {
    var el = document.getElementById('calSummary');
    var byHost = {};
    data.shows.forEach(function(s) {
        if (!s.host_ids) return;
        var ids   = s.host_ids.split(',');
        var names = (s.host_names || '').split(',');
        var rates = (s.host_rates || '').split(',');
        var dur   = parseFloat(s.estimated_duration_hrs || data.show_dur || 4);
        ids.forEach(function(id, i) {
            id = parseInt(id);
            if (!byHost[id]) byHost[id] = {
                name: names[i] || '', rate: parseFloat(rates[i] || 0),
                color: _hostColorMap[id] || '#888', shows: 0, hrs: 0
            };
            byHost[id].shows++;
            byHost[id].hrs += dur;
        });
    });
    var rows = Object.values(byHost);
    if (!rows.length) { el.innerHTML = '<div class="p-3 text-muted small">Sin shows en el período.</div>'; return; }
    var totShows = rows.reduce(function(a,b){return a+b.shows;},0);
    var totHrs   = rows.reduce(function(a,b){return a+b.hrs;},0);
    var totEarn  = rows.reduce(function(a,b){return a+b.hrs*b.rate;},0);
    el.innerHTML = '<table class="table table-sm mb-0">'
        + '<thead><tr><th>Streamer</th><th class="text-center">Shows</th><th class="text-center">Horas</th><th class="text-end">Proyectado</th></tr></thead>'
        + '<tbody>'
        + rows.map(function(r) {
            return '<tr><td><span class="badge me-1" style="background:' + r.color + '">&nbsp;</span>' + esc(r.name) + '</td>'
                + '<td class="text-center">' + r.shows + '</td>'
                + '<td class="text-center">' + r.hrs.toFixed(0) + ' hrs</td>'
                + '<td class="text-end fw-bold text-warning">' + fmt2(r.hrs * r.rate) + '</td></tr>';
        }).join('')
        + '<tr class="fw-bold border-top"><td>Total</td><td class="text-center">' + totShows + '</td>'
        + '<td class="text-center">' + totHrs.toFixed(0) + ' hrs</td>'
        + '<td class="text-end text-warning">' + fmt2(totEarn) + '</td></tr>'
        + '</tbody></table>';
}

function formatDate(dt) {
    var d = new Date(dt);
    return d.toLocaleDateString('es-MX', {weekday:'short', day:'numeric', month:'short'}) + ' ' + d.toLocaleTimeString('es-MX', {hour:'2-digit', minute:'2-digit'});
}

<?php if ($is_admin): ?>
function openCreate(prefillDate, prefillTime) {
    document.getElementById('modalTitle').textContent = 'Nuevo show';
    document.getElementById('fId').value = '';
    document.getElementById('fTitle').value = '';
    document.getElementById('fDate').value = prefillDate ? (prefillDate + 'T' + (prefillTime || '08:00')) : '';
    document.getElementById('fDuration').value = '4';
    document.querySelectorAll('.host-check').forEach(function(c) { c.checked = false; });
    if (prefillDate && typeof _calAvailMap !== 'undefined') {
        var dow = new Date(prefillDate + 'T00:00:00').getDay();
        var isoDow = dow === 0 ? 7 : dow;
        document.querySelectorAll('.host-check').forEach(function(c) {
            var hid = parseInt(c.value);
            c.checked = !!(_calAvailMap[hid] && _calAvailMap[hid][isoDow]);
        });
    }
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
        var hostIds = (r.host_ids || '').split(',').filter(Boolean);
        document.querySelectorAll('.host-check').forEach(function(c) {
            c.checked = hostIds.indexOf(c.value) >= 0;
        });
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
        host_ids: Array.from(document.querySelectorAll('.host-check:checked')).map(function(c) { return parseInt(c.value); }),
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

var _searchTimer   = null;
var _searchResults = [];

function toggleAddPanel() {
    var panel = document.getElementById('addPanel');
    if (!panel) return;
    panel.classList.toggle('d-none');
    if (!panel.classList.contains('d-none')) {
        document.getElementById('prodSearch').focus();
    }
}

function searchProducts(q) {
    clearTimeout(_searchTimer);
    var res = document.getElementById('searchResults');
    if (q.length < 2) { res.innerHTML = ''; return; }
    _searchTimer = setTimeout(function() {
        apiFetch('?action=search_products&q=' + encodeURIComponent(q)).then(function(d) {
            if (!d.ok) return;
            _searchResults = d.data;
            if (!_searchResults.length) {
                res.innerHTML = '<div class="text-muted small py-1">Sin resultados.</div>';
                return;
            }
            res.innerHTML = '<div class="list-group list-group-flush" style="max-height:200px;overflow-y:auto">'
                + _searchResults.map(function(p, i) {
                    return '<button type="button" class="list-group-item list-group-item-action list-group-item-dark py-1 px-2 text-start" onclick="selectProduct(' + i + ')">'
                        + '<span class="fw-semibold">' + esc(p.brand) + '</span> — ' + esc(p.description)
                        + (p.color ? ' <span class="text-muted small">' + esc(p.color) + '</span>' : '')
                        + ' <span class="badge bg-secondary ms-1">Stock: ' + p.stock_qty + '</span>'
                        + ' <span class="badge bg-warning text-dark ms-1">' + fmt2(p.rescue_price_usd) + '</span>'
                        + '</button>';
                }).join('')
                + '</div>';
        });
    }, 300);
}

function selectProduct(idx) {
    var p = _searchResults[idx];
    if (!p) return;
    document.getElementById('selProductId').value = p.id;
    document.getElementById('selProductLabel').textContent = p.brand + ' — ' + p.description;
    document.getElementById('selBid').value = parseFloat(p.rescue_price_usd).toFixed(2);
    document.getElementById('selQty').value = 1;
    document.getElementById('addForm').classList.remove('d-none');
    document.getElementById('searchResults').innerHTML = '';
    document.getElementById('prodSearch').value = '';
    document.getElementById('selQty').focus();
}

function clearAddForm() {
    var panel = document.getElementById('addPanel');
    if (panel) panel.classList.add('d-none');
    var res = document.getElementById('searchResults');
    if (res) res.innerHTML = '';
    var form = document.getElementById('addForm');
    if (form) form.classList.add('d-none');
    var search = document.getElementById('prodSearch');
    if (search) search.value = '';
    _searchResults = [];
}

function addProduct(showId) {
    var pidEl = document.getElementById('selProductId');
    if (!pidEl || !pidEl.value) { toast('Selecciona un producto primero.', 'error'); return; }
    var qty = parseInt(document.getElementById('selQty').value);
    var bid = parseFloat(document.getElementById('selBid').value) || 0;
    if (!qty || qty < 1) { toast('Qty debe ser ≥ 1.', 'error'); return; }
    apiFetch('?action=add_product', { body: {
        show_id: showId, product_id: parseInt(pidEl.value), qty_listed: qty, starting_bid_usd: bid
    }}).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        toast('Producto agregado al lineup.');
        clearAddForm();
        loadLineup(showId);
    });
}

function removeProduct(spId, showId) {
    if (!confirm('¿Quitar este producto del lineup?')) return;
    apiFetch('?action=remove_product', { body: { id: spId, show_id: showId } }).then(function(d) {
        if (!d.ok) { toast(d.error, 'error'); return; }
        toast('Producto removido.');
        loadLineup(showId);
    });
}
<?php endif ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
