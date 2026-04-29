<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php';
require __DIR__ . '/push.php';

$cfg          = use_config();
$expected     = (string)($cfg['admin_token'] ?? '');
$got          = (string)($_GET['token'] ?? '');
$appsScriptUrl = (string)($cfg['apps_script_url'] ?? '');

if ($expected === '' || !hash_equals($expected, $got)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

if ($appsScriptUrl === '') {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'apps_script_url not configured']);
  exit;
}

@set_time_limit(300);

$pdo  = use_db();
$stmt = $pdo->prepare(
  "SELECT id FROM leads
   WHERE status = 'pending' AND attempts < :max
   ORDER BY created_at ASC
   LIMIT 50"
);
$stmt->bindValue(':max', PUSH_MAX_ATTEMPTS, PDO::PARAM_INT);
$stmt->execute();
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$results = [];
foreach ($ids as $id) {
  $row = $pdo->prepare('SELECT payload FROM leads WHERE id = ?');
  $row->execute([(int)$id]);
  $payload = $row->fetchColumn();
  if ($payload === false) continue;
  $ok = push_lead_to_apps_script($pdo, (int)$id, (string)$payload, $appsScriptUrl);
  $results[] = ['id' => (int)$id, 'ok' => $ok];
}

echo json_encode(['ok' => true, 'processed' => count($results), 'results' => $results]);
