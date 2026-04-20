<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$page_title   = 'Dashboard — beautyhauss ERP';
$current_page = 'dashboard';
$user         = current_user();
$is_admin     = $user['role'] === 'admin';

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
<?php include __DIR__ . '/includes/footer.php'; ?>
