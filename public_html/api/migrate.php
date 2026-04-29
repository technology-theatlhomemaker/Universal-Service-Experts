<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php';

$cfg      = use_config();
$expected = (string)($cfg['admin_token'] ?? '');
$got      = (string)($_GET['token'] ?? '');
if ($expected === '' || !hash_equals($expected, $got)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

@set_time_limit(120);

$pdo = use_db();

// Tracking table — created on first migrate run.
$pdo->exec(
  "CREATE TABLE IF NOT EXISTS schema_migrations (
     version    VARCHAR(255) NOT NULL PRIMARY KEY,
     applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$applied = [];
foreach ($pdo->query('SELECT version FROM schema_migrations') as $row) {
  $applied[$row['version']] = true;
}

$dir   = __DIR__ . '/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

$results = [];
foreach ($files as $path) {
  $version = basename($path, '.sql');

  if (isset($applied[$version])) {
    $results[] = ['version' => $version, 'status' => 'skipped'];
    continue;
  }

  $sql = file_get_contents($path);
  if ($sql === false || trim($sql) === '') {
    $results[] = ['version' => $version, 'status' => 'empty'];
    continue;
  }

  try {
    $pdo->exec($sql);
    $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
    $stmt->execute([$version]);
    $results[] = ['version' => $version, 'status' => 'applied'];
  } catch (Throwable $e) {
    // MySQL DDL auto-commits, so a partially-applied migration may leave the
    // schema in a half state. Fix the SQL and re-run; the schema_migrations
    // row was never inserted, so this version stays pending.
    $results[] = [
      'version' => $version,
      'status'  => 'failed',
      'error'   => $e->getMessage(),
    ];
    http_response_code(500);
    echo json_encode(['ok' => false, 'results' => $results]);
    exit;
  }
}

echo json_encode(['ok' => true, 'results' => $results]);
