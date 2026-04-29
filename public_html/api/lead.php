<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
  echo json_encode(['ok' => true]);
  exit;
}

if (strlen($raw) > 50 * 1024 * 1024) {
  http_response_code(413);
  echo json_encode(['ok' => false, 'error' => 'Payload too large']);
  exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
  echo json_encode(['ok' => true]);
  exit;
}

if (!empty($payload['form_fields[field_ffc5e7c]'])) {
  echo json_encode(['ok' => true]);
  exit;
}

$age = isset($payload['_form_age_ms']) ? (int)$payload['_form_age_ms'] : 0;
if ($age < 3000) {
  echo json_encode(['ok' => true]);
  exit;
}

$firstName = trim((string)($payload['form_fields[name]'] ?? ''));
$email     = trim((string)($payload['form_fields[field_6817b28]'] ?? ''));
if ($firstName === '' || $email === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
  exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/push.php';

try {
  $pdo  = use_db();
  $stmt = $pdo->prepare(
    'INSERT INTO leads (payload, source_page, ip_address) VALUES (?, ?, ?)'
  );
  $stmt->execute([
    $raw,
    substr((string)($payload['_source_page'] ?? ''), 0, 255),
    substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
  ]);
  $leadId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
  error_log('lead insert failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Could not save lead']);
  exit;
}

echo json_encode(['ok' => true, 'redirectUrl' => '/thank-you/']);

if (function_exists('fastcgi_finish_request')) {
  fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
  litespeed_finish_request();
} else {
  if (ob_get_level() > 0) ob_end_flush();
  flush();
}

ignore_user_abort(true);
@set_time_limit(120);

$cfg = use_config();
$url = (string)($cfg['apps_script_url'] ?? '');
if ($url === '') {
  error_log('apps_script_url not configured; lead ' . $leadId . ' stays pending');
  exit;
}

push_lead_to_apps_script($pdo, $leadId, $raw, $url);

try {
  drain_pending($pdo, $url, $leadId);
} catch (Throwable $e) {
  error_log('piggyback retry failed: ' . $e->getMessage());
}
