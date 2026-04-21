<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$results = [];

$migrations = [
    "ALTER TABLE show_products ADD COLUMN IF NOT EXISTS whatnot_slot VARCHAR(10) DEFAULT NULL",
];

foreach ($migrations as $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ['ok' => true, 'sql' => $sql];
    } catch (\PDOException $e) {
        $results[] = ['ok' => false, 'sql' => $sql, 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html><html><head><title>Migration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4">
<h4>beautyhauss — DB Migrations</h4>
<?php foreach ($results as $r): ?>
<div class="alert alert-<?= $r['ok'] ? 'success' : 'danger' ?>">
  <code><?= htmlspecialchars($r['sql']) ?></code><br>
  <?= $r['ok'] ? '✓ OK' : '✗ ' . htmlspecialchars($r['error']) ?>
</div>
<?php endforeach ?>
<a href="/shows" class="btn btn-primary">Ir a Shows</a>
</body></html>
