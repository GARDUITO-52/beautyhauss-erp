<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$page_title   = 'Dashboard — beautyhauss ERP';
$current_page = 'dashboard';
$user         = current_user();
$is_admin     = $user['role'] === 'admin';

// ── AJAX ───────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    require_admin();
    if ($_GET['action'] === 'save_goal') {
        csrfGuard();
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        $keys = ['goal_amount_usd', 'goal_start_date', 'goal_end_date', 'streamer_hourly_usd', 'whatnot_pct_fee', 'whatnot_flat_fee'];
        $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?,?) ON DUPLICATE KEY UPDATE config_value=?");
        foreach ($keys as $k) {
            if (isset($d[$k]) && $d[$k] !== '') $stmt->execute([$k, $d[$k], $d[$k]]);
        }
        jsonOk();
    }
    jsonErr('Acción no reconocida.', 400);
}

// ── STAFF DASHBOARD ────────────────────────────────────────────────────────
if (!$is_admin) {
    $next_show = $pdo->query("
        SELECT s.id, s.title, s.scheduled_at, h.name AS host
        FROM shows s
        LEFT JOIN hosts h ON h.id = s.host_id
        WHERE s.status IN ('SCHEDULED','LIVE')
        ORDER BY s.scheduled_at ASC
        LIMIT 1
    ")->fetch();

    $to_pack = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE packed_at IS NULL AND status='FULFILLED'")->fetchColumn();
    $packed_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(packed_at) = CURDATE()")->fetchColumn();

    include __DIR__ . '/includes/header.php';
    include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <div class="mb-4">
    <h1 class="h3 fw-bold mb-0">Hola, <?= htmlspecialchars($user['name']) ?> 👋</h1>
    <div class="text-muted small"><?= date('l j \d\e F, Y') ?></div>
  </div>

  <!-- Próximo show -->
  <div class="card shadow-sm mb-3" style="border-left:4px solid #d4537e">
    <div class="card-body">
      <div class="text-white-50 small fw-semibold text-uppercase mb-1">Próximo Show</div>
      <?php if ($next_show): ?>
        <div class="fw-bold fs-5"><?= htmlspecialchars($next_show['title']) ?></div>
        <div class="text-muted small">
          <?= date('l j \d\e F, g:ia', strtotime($next_show['scheduled_at'])) ?>
          <?= $next_show['host'] ? '· Host: ' . htmlspecialchars($next_show['host']) : '' ?>
        </div>
        <a href="/shows?id=<?= $next_show['id'] ?>" class="btn btn-sm mt-2 fw-bold" style="background:#d4537e;color:#fff">
          <i class="bi bi-list-ul me-1"></i>Ver Lineup
        </a>
      <?php else: ?>
        <div class="text-muted">Sin shows programados.</div>
      <?php endif ?>
    </div>
  </div>

  <!-- Packing counters -->
  <div class="row g-3">
    <div class="col-6">
      <div class="card shadow-sm text-center h-100">
        <div class="card-body py-4">
          <div class="fw-bold" style="font-size:2.5rem;color:#d4537e"><?= $to_pack ?></div>
          <div class="text-muted small mt-1"><i class="bi bi-box me-1"></i>Por empacar</div>
          <a href="/orders" class="btn btn-sm btn-outline-secondary mt-2">Ver cola</a>
        </div>
      </div>
    </div>
    <div class="col-6">
      <div class="card shadow-sm text-center h-100">
        <div class="card-body py-4">
          <div class="fw-bold text-success" style="font-size:2.5rem"><?= $packed_today ?></div>
          <div class="text-muted small mt-1"><i class="bi bi-check2-circle me-1"></i>Empacadas hoy</div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ── ADMIN DASHBOARD ────────────────────────────────────────────────────────
$cfg = $pdo->query("SELECT config_key, config_value FROM system_config")->fetchAll(PDO::FETCH_KEY_PAIR);
$goal        = (float)($cfg['goal_amount_usd'] ?? 60000);
$start_date  = $cfg['goal_start_date'] ?? date('Y-m-d');
$end_date    = $cfg['goal_end_date']   ?? date('Y-m-d', strtotime('+30 days'));
$days_total  = max(1, (strtotime($end_date) - strtotime($start_date)) / 86400);
$days_passed = max(0, (time() - strtotime($start_date)) / 86400);
$days_left   = max(0, ceil($days_total - $days_passed));

$revenue     = (float)$pdo->query("SELECT COALESCE(SUM(sale_amount_usd),0) FROM orders WHERE status='FULFILLED'")->fetchColumn();
$net_earn    = (float)$pdo->query("SELECT COALESCE(SUM(net_earnings_usd),0) FROM orders WHERE status='FULFILLED'")->fetchColumn();
$cogs        = (float)$pdo->query("SELECT COALESCE(SUM(cogs_usd),0) FROM orders WHERE status='FULFILLED'")->fetchColumn();
$total_exp   = (float)$pdo->query("SELECT COALESCE(SUM(amount_usd),0) FROM expenses")->fetchColumn();
$net_profit  = $net_earn - $cogs - $total_exp;
$order_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='FULFILLED'")->fetchColumn();
$aov         = $order_count > 0 ? $revenue / $order_count : 0;
$gross_margin = $revenue > 0 ? (($net_earn - $cogs) / $revenue * 100) : 0;

$total_units = (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM purchase_batch_items")->fetchColumn();
$sold_units  = (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM order_items")->fetchColumn();
$sell_through = $total_units > 0 ? ($sold_units / $total_units * 100) : 0;

$inv = $pdo->query("SELECT COUNT(*) AS skus, COALESCE(SUM(stock_qty),0) AS units FROM products WHERE stock_qty > 0")->fetch();
$stream_hours  = round($inv['units'] * 5 / 3600, 1);

// Goal-aware streaming math
$streamer_hourly  = (float)($cfg['streamer_hourly_usd'] ?? 50);
$avg_unit_price   = $sold_units > 0
    ? $revenue / $sold_units
    : (float)$pdo->query("SELECT COALESCE(AVG(rescue_price_usd),0) FROM products WHERE rescue_price_usd > 0")->fetchColumn();
$remaining_goal   = max(0, $goal - $revenue);
$units_for_goal   = $avg_unit_price > 0 ? (int)ceil($remaining_goal / $avg_unit_price) : 0;
$hours_for_goal   = round($units_for_goal * 5 / 3600, 1);
$streamer_cost    = round($hours_for_goal * $streamer_hourly, 2);
$hrs_per_day      = $days_left > 0 && $hours_for_goal > 0 ? round($hours_for_goal / $days_left, 1) : 0;
$active_hosts     = (int)$pdo->query("SELECT COUNT(*) FROM hosts WHERE is_active=1")->fetchColumn();
$hrs_per_host_day = $active_hosts > 0 && $hrs_per_day > 0 ? round($hrs_per_day / $active_hosts, 1) : 0;
$shows_goal_2h    = $units_for_goal > 0 ? (int)ceil($hours_for_goal / 2) : 0;
$weeks_3 = $shows_goal_2h > 0 ? (int)ceil($shows_goal_2h / 3) : 0;
$weeks_5 = $shows_goal_2h > 0 ? (int)ceil($shows_goal_2h / 5) : 0;
$weeks_7 = $shows_goal_2h > 0 ? (int)ceil($shows_goal_2h / 7) : 0;

$progress_pct = $goal > 0 ? min(100, ($revenue / $goal * 100)) : 0;
$pace_daily   = $days_passed > 0 ? $revenue / $days_passed : 0;
$projected    = $pace_daily * $days_total;

$dead = $pdo->query("
    SELECT p.brand, p.description, p.stock_qty, p.cost_usd,
           ROUND(p.stock_qty * p.cost_usd, 2) AS capital
    FROM products p
    WHERE p.stock_qty > 0
      AND p.id NOT IN (
          SELECT DISTINCT oi.product_id FROM order_items oi
          JOIN orders o ON o.id = oi.order_id
          WHERE o.order_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            AND o.status = 'FULFILLED'
      )
    ORDER BY capital DESC LIMIT 5
")->fetchAll();

$recent_shows = $pdo->query("
    SELECT s.title, s.scheduled_at, h.name AS host,
           COALESCE(SUM(o.sale_amount_usd),0) AS revenue,
           COALESCE(SUM(o.net_earnings_usd),0) - COALESCE(SUM(o.cogs_usd),0) AS gross_profit
    FROM shows s
    LEFT JOIN hosts h ON h.id = s.host_id
    LEFT JOIN orders o ON o.show_id = s.id AND o.status = 'FULFILLED'
    WHERE s.status = 'COMPLETED'
    GROUP BY s.id ORDER BY s.scheduled_at DESC LIMIT 5
")->fetchAll();

$to_pack = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE packed_at IS NULL AND status='FULFILLED'")->fetchColumn();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 fw-bold">Dashboard</h1>
    <div class="d-flex gap-2 align-items-center">
      <?php if ($to_pack > 0): ?>
        <a href="/orders" class="badge text-decoration-none" style="background:#d4537e;font-size:.85rem;padding:.4em .7em">
          <i class="bi bi-box me-1"></i><?= $to_pack ?> por empacar
        </a>
      <?php endif ?>
      <span class="badge bg-secondary"><?= $days_left ?> días restantes</span>
    </div>
  </div>

  <!-- 30-Day Goal -->
  <div class="card kpi-card shadow-sm mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between mb-2">
        <span class="fw-bold">Progreso hacia meta $<?= number_format($goal,0) ?> USD</span>
        <span class="fw-bold <?= $revenue >= $goal ? 'text-success' : ($projected < $goal ? 'text-danger' : 'text-warning') ?>">
          $<?= number_format($revenue,2) ?> / $<?= number_format($goal,0) ?>
        </span>
      </div>
      <div class="progress progress-goal mb-2">
        <div class="progress-bar <?= $projected < $goal ? 'bg-danger' : 'bg-success' ?>"
             style="width:<?= $progress_pct ?>%"></div>
      </div>
      <div class="d-flex justify-content-between small text-muted">
        <span>Al ritmo actual: proyección <strong>$<?= number_format($projected,0) ?></strong></span>
        <?php if ($projected < $goal): ?>
          <span class="text-danger">⚠ Por debajo de la meta</span>
        <?php else: ?>
          <span class="text-success">✓ En buen ritmo</span>
        <?php endif ?>
      </div>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <?php
    $kpis = [
      ['Revenue Bruto',   '$'.number_format($revenue,2).' USD',    'primary'],
      ['Net Earnings',    '$'.number_format($net_earn,2).' USD',   'success'],
      ['Net Profit',      '$'.number_format($net_profit,2).' USD', $net_profit >= 0 ? 'success' : 'danger'],
      ['Gross Margin',    number_format($gross_margin,1).'%',      'info'],
      ['Sell-Through',    number_format($sell_through,1).'%',      'warning'],
      ['AOV',             '$'.number_format($aov,2).' USD',        'secondary'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="card kpi-card shadow-sm h-100">
        <div class="card-body text-center p-3">
          <div class="kpi-value text-<?= $k[2] ?>" style="font-size:1.4rem"><?= $k[1] ?></div>
          <div class="text-muted small mt-1"><?= $k[0] ?></div>
        </div>
      </div>
    </div>
    <?php endforeach ?>
  </div>

  <!-- Streaming Plan -->
  <div class="card shadow-sm mb-4" style="border-left:4px solid #d4537e">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="fw-bold"><i class="bi bi-camera-video me-2" style="color:#d4537e"></i>Streaming Plan</div>
        <button class="btn btn-sm btn-outline-secondary" onclick="openGoalConfig()">
          <i class="bi bi-gear me-1"></i>Configurar meta
        </button>
      </div>
      <?php if ($inv['units'] > 0): ?>
      <div class="row g-3">
        <!-- Inventario -->
        <div class="col-md-4">
          <div class="small text-muted text-uppercase fw-semibold mb-2">Inventario total</div>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small">SKUs en stock</span>
            <span class="fw-bold text-warning"><?= number_format($inv['skus']) ?></span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small">Unidades</span>
            <span class="fw-bold text-info"><?= number_format($inv['units']) ?></span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small">Horas de stream total</span>
            <span class="fw-bold"><?= $stream_hours ?>h</span>
          </div>
          <!-- Show calculator -->
          <div class="mt-3 p-2 rounded" style="background:rgba(255,255,255,.05)">
            <div class="text-muted small fw-semibold mb-2">Calculadora de show</div>
            <div class="input-group input-group-sm">
              <input type="number" id="showDurInput" class="form-control bg-dark text-white border-secondary"
                     placeholder="Duración (hrs)" min="0.5" step="0.5" value="3" oninput="calcShow()">
              <span class="input-group-text bg-dark text-secondary border-secondary">hrs</span>
            </div>
            <div class="mt-1 text-muted small">→ <span id="showCalcOut" class="fw-bold text-white">2,160</span> productos</div>
          </div>
        </div>
        <!-- Meta + hrs/día -->
        <div class="col-md-4 border-start border-secondary">
          <div class="small text-muted text-uppercase fw-semibold mb-2">Para meta $<?= number_format($goal, 0) ?></div>
          <?php if ($avg_unit_price > 0): ?>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small">Precio prom/unidad</span>
            <span class="fw-bold">$<?= number_format($avg_unit_price, 2) ?></span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small">Unidades a vender</span>
            <span class="fw-bold text-success"><?= number_format($units_for_goal) ?></span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small">Horas totales necesarias</span>
            <span class="fw-bold"><?= $hours_for_goal ?>h</span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small">Costo streamer (@$<?= number_format($streamer_hourly, 0) ?>/hr)</span>
            <span class="fw-bold text-danger">$<?= number_format($streamer_cost, 0) ?></span>
          </div>
          <?php if ($hrs_per_day > 0): ?>
          <hr class="border-secondary my-2">
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small fw-semibold">Hrs/día necesarias</span>
            <span class="fw-bold fs-6" style="color:#d4537e"><?= $hrs_per_day ?>h/día</span>
          </div>
          <?php if ($active_hosts > 0): ?>
          <div class="d-flex justify-content-between">
            <span class="text-muted small"><?= $active_hosts ?> host<?= $active_hosts > 1 ? 's' : '' ?> activ<?= $active_hosts > 1 ? 'as' : 'a' ?></span>
            <span class="fw-bold text-info"><?= $hrs_per_host_day ?>h/host/día</span>
          </div>
          <?php else: ?>
          <div class="text-muted" style="font-size:.72rem">Agrega hosts para ver desglose por streamer</div>
          <?php endif ?>
          <?php endif ?>
          <?php else: ?>
          <p class="text-muted small mb-0">Genera productos para calcular precio promedio.</p>
          <?php endif ?>
        </div>
        <!-- Calendario Shabbat -->
        <div class="col-md-4 border-start border-secondary">
          <div class="small text-muted text-uppercase fw-semibold mb-2">Calendario <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">Shabbat off</span></div>
          <?php if ($shows_goal_2h > 0): ?>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small">3 shows/semana</span>
            <span class="fw-bold"><?= $weeks_3 ?> semanas</span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small">5 shows/semana</span>
            <span class="fw-bold text-warning"><?= $weeks_5 ?> semanas</span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span class="text-muted small">7 shows/semana</span>
            <span class="fw-bold text-success"><?= $weeks_7 ?> semanas</span>
          </div>
          <div class="text-muted mt-2" style="font-size:.7rem">Vie 6pm → Sáb 9pm bloqueado · Shows de 2h</div>
          <?php else: ?>
          <p class="text-muted small mb-0">Configura meta para ver proyección.</p>
          <?php endif ?>
        </div>
      </div>
      <div class="text-muted mt-3" style="font-size:.72rem">Calculado a 5 seg/unidad</div>
      <?php else: ?>
      <p class="text-muted mb-0">Sin inventario en stock todavía. Importa un lote para ver el streaming plan.</p>
      <?php endif ?>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card shadow-sm h-100">
        <div class="card-header fw-bold">⚠ Dead Stock (sin ventas 7 días)</div>
        <div class="card-body p-0">
          <?php if (empty($dead)): ?>
            <p class="text-muted p-3 mb-0">Sin dead stock detectado.</p>
          <?php else: ?>
          <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Producto</th><th>Stock</th><th>Capital USD</th></tr></thead>
            <tbody>
            <?php foreach ($dead as $d): ?>
              <tr>
                <td><small><?= htmlspecialchars($d['brand'].' '.$d['description']) ?></small></td>
                <td><?= $d['stock_qty'] ?></td>
                <td class="text-danger fw-bold">$<?= number_format($d['capital'],2) ?></td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
          <?php endif ?>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card shadow-sm h-100">
        <div class="card-header fw-bold">📺 Últimos Shows</div>
        <div class="card-body p-0">
          <?php if (empty($recent_shows)): ?>
            <p class="text-muted p-3 mb-0">Sin shows completados aún.</p>
          <?php else: ?>
          <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Show</th><th>Revenue</th><th>Gross Profit</th></tr></thead>
            <tbody>
            <?php foreach ($recent_shows as $s): ?>
              <tr>
                <td><small><?= htmlspecialchars($s['title']) ?><br>
                  <span class="text-muted"><?= date('d/m', strtotime($s['scheduled_at'])) ?> · <?= htmlspecialchars($s['host'] ?? '—') ?></span>
                </small></td>
                <td>$<?= number_format($s['revenue'],0) ?></td>
                <td class="<?= $s['gross_profit'] >= 0 ? 'text-success' : 'text-danger' ?> fw-bold">
                  $<?= number_format($s['gross_profit'],0) ?>
                </td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
          <?php endif ?>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Modal: Goal Config -->
<div class="modal fade" id="modalGoal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog">
    <div class="modal-content bg-dark text-white">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="bi bi-gear me-2"></i>Configurar meta & streaming</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Meta de ventas USD</label>
          <div class="input-group">
            <span class="input-group-text bg-dark text-white border-secondary">$</span>
            <input type="number" id="gGoal" class="form-control bg-dark text-white border-secondary" step="1000" min="0" value="<?= number_format($goal, 0, '.', '') ?>">
          </div>
        </div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Fecha inicio</label>
            <input type="date" id="gStart" class="form-control bg-dark text-white border-secondary" value="<?= htmlspecialchars($cfg['goal_start_date'] ?? '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Fecha fin</label>
            <input type="date" id="gEnd" class="form-control bg-dark text-white border-secondary" value="<?= htmlspecialchars($cfg['goal_end_date'] ?? '') ?>">
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label">Tarifa streamer (USD/hora)</label>
          <div class="input-group">
            <span class="input-group-text bg-dark text-white border-secondary">$</span>
            <input type="number" id="gRate" class="form-control bg-dark text-white border-secondary" step="5" min="0" value="<?= number_format($streamer_hourly, 0, '.', '') ?>">
            <span class="input-group-text bg-dark text-white border-secondary">/hr</span>
          </div>
        </div>
        <hr class="border-secondary mt-3 mb-2">
        <div class="text-muted small mb-2">Fees Whatnot</div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Comisión % <span class="text-muted small">(ej. 10.9)</span></label>
            <div class="input-group">
              <input type="number" id="gWnPct" class="form-control bg-dark text-white border-secondary" step="0.1" min="0" max="50" value="<?= number_format((float)($cfg['whatnot_pct_fee'] ?? 0.109) * 100, 1, '.', '') ?>">
              <span class="input-group-text bg-dark text-white border-secondary">%</span>
            </div>
          </div>
          <div class="col-6">
            <label class="form-label">Fee fijo <span class="text-muted small">(por venta)</span></label>
            <div class="input-group">
              <span class="input-group-text bg-dark text-white border-secondary">$</span>
              <input type="number" id="gWnFlat" class="form-control bg-dark text-white border-secondary" step="0.01" min="0" value="<?= number_format((float)($cfg['whatnot_flat_fee'] ?? 0.30), 2, '.', '') ?>">
            </div>
          </div>
        </div>
        <div id="goalError" class="alert alert-danger py-2 mt-3 d-none"></div>
      </div>
      <div class="modal-footer border-secondary">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn fw-bold" style="background:#d4537e;color:#fff" onclick="saveGoal()">Guardar</button>
      </div>
    </div>
  </div>
</div>

<script>
function calcShow() {
    var hrs = parseFloat(document.getElementById('showDurInput').value) || 0;
    var prods = Math.floor(hrs * 3600 / 5);
    document.getElementById('showCalcOut').textContent = prods.toLocaleString();
}
document.addEventListener('DOMContentLoaded', calcShow);

function openGoalConfig() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalGoal')).show();
}
function saveGoal() {
    var payload = {
        goal_amount_usd:     document.getElementById('gGoal').value,
        goal_start_date:     document.getElementById('gStart').value,
        goal_end_date:       document.getElementById('gEnd').value,
        streamer_hourly_usd: document.getElementById('gRate').value,
        whatnot_pct_fee:     (parseFloat(document.getElementById('gWnPct').value) / 100).toFixed(4),
        whatnot_flat_fee:    document.getElementById('gWnFlat').value,
    };
    apiFetch('?action=save_goal', { body: payload }).then(function(d) {
        if (!d.ok) {
            document.getElementById('goalError').textContent = d.error;
            document.getElementById('goalError').classList.remove('d-none');
            return;
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalGoal')).hide();
        toast('Meta actualizada. Recargando…');
        setTimeout(function() { location.reload(); }, 1000);
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
