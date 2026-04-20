<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$page_title  = 'Dashboard — beautyhauss ERP';
$current_page = 'dashboard';

// Config
$cfg = $pdo->query("SELECT config_key, config_value FROM system_config")->fetchAll(PDO::FETCH_KEY_PAIR);
$goal        = (float)($cfg['goal_amount_usd'] ?? 60000);
$start_date  = $cfg['goal_start_date'] ?? date('Y-m-d');
$end_date    = $cfg['goal_end_date']   ?? date('Y-m-d', strtotime('+30 days'));
$days_total  = max(1, (strtotime($end_date) - strtotime($start_date)) / 86400);
$days_passed = max(0, (time() - strtotime($start_date)) / 86400);
$days_left   = max(0, ceil($days_total - $days_passed));

// KPIs
$revenue     = (float)$pdo->query("SELECT COALESCE(SUM(sale_amount_usd),0) FROM orders WHERE status='FULFILLED'")->fetchColumn();
$net_earn    = (float)$pdo->query("SELECT COALESCE(SUM(net_earnings_usd),0) FROM orders WHERE status='FULFILLED'")->fetchColumn();
$cogs        = (float)$pdo->query("SELECT COALESCE(SUM(cogs_usd),0) FROM orders WHERE status='FULFILLED'")->fetchColumn();
$total_exp   = (float)$pdo->query("SELECT COALESCE(SUM(amount_usd),0) FROM expenses")->fetchColumn();
$net_profit  = $net_earn - $cogs - $total_exp;
$order_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='FULFILLED'")->fetchColumn();
$aov         = $order_count > 0 ? $revenue / $order_count : 0;
$gross_margin = $revenue > 0 ? (($net_earn - $cogs) / $revenue * 100) : 0;

// Sell-through
$total_units = (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM purchase_batch_items")->fetchColumn();
$sold_units  = (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM order_items")->fetchColumn();
$sell_through = $total_units > 0 ? ($sold_units / $total_units * 100) : 0;

// Progress
$progress_pct = $goal > 0 ? min(100, ($revenue / $goal * 100)) : 0;
$pace_daily   = $days_passed > 0 ? $revenue / $days_passed : 0;
$projected    = $pace_daily * $days_total;

// Dead stock (no sales in 7 days)
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
    ORDER BY capital DESC
    LIMIT 5
")->fetchAll();

// Recent shows
$recent_shows = $pdo->query("
    SELECT s.title, s.scheduled_at, h.name AS host,
           COALESCE(SUM(o.sale_amount_usd),0) AS revenue,
           COALESCE(SUM(o.net_earnings_usd),0) - COALESCE(SUM(o.cogs_usd),0) AS gross_profit
    FROM shows s
    LEFT JOIN hosts h ON h.id = s.host_id
    LEFT JOIN orders o ON o.show_id = s.id AND o.status = 'FULFILLED'
    WHERE s.status = 'COMPLETED'
    GROUP BY s.id
    ORDER BY s.scheduled_at DESC
    LIMIT 5
")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 fw-bold">Dashboard</h1>
    <span class="badge bg-secondary"><?= $days_left ?> días restantes</span>
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
      ['Revenue Bruto',   '$'.number_format($revenue,2).' USD',  'primary'],
      ['Net Earnings',    '$'.number_format($net_earn,2).' USD', 'success'],
      ['Net Profit',      '$'.number_format($net_profit,2).' USD', $net_profit >= 0 ? 'success' : 'danger'],
      ['Gross Margin',    number_format($gross_margin,1).'%',    'info'],
      ['Sell-Through',    number_format($sell_through,1).'%',    'warning'],
      ['AOV',             '$'.number_format($aov,2).' USD',      'secondary'],
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
    <!-- Dead Stock -->
    <div class="col-md-6">
      <div class="card shadow-sm h-100">
        <div class="card-header fw-bold">⚠ Dead Stock (sin ventas en 7 días)</div>
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

    <!-- Recent Shows -->
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
