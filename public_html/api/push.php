<?php
declare(strict_types=1);

const PUSH_MAX_ATTEMPTS = 5;
const PUSH_TIMEOUT_SEC = 90;
const PUSH_CONNECT_TIMEOUT_SEC = 10;

function push_lead_to_apps_script(PDO $pdo, int $leadId, string $payload, string $url): bool {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: text/plain;charset=utf-8'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => PUSH_TIMEOUT_SEC,
    CURLOPT_CONNECTTIMEOUT => PUSH_CONNECT_TIMEOUT_SEC,
  ]);
  $body     = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr  = curl_error($ch);
  curl_close($ch);

  $ok       = false;
  $errorMsg = null;
  if ($body !== false && $httpCode >= 200 && $httpCode < 300) {
    $decoded = json_decode((string)$body, true);
    if (is_array($decoded) && !empty($decoded['ok'])) {
      $ok = true;
    } else {
      $errorMsg = 'apps script body: ' . substr((string)$body, 0, 500);
    }
  } else {
    $errorMsg = $curlErr !== '' ? $curlErr : ('http ' . $httpCode);
  }

  try {
    if ($ok) {
      $u = $pdo->prepare(
        "UPDATE leads
         SET status = 'sent',
             sent_at = NOW(),
             attempts = attempts + 1,
             last_attempt_at = NOW(),
             last_error = NULL
         WHERE id = :id"
      );
      $u->execute([':id' => $leadId]);
    } else {
      $u = $pdo->prepare(
        "UPDATE leads
         SET attempts = attempts + 1,
             last_attempt_at = NOW(),
             last_error = :err,
             status = CASE WHEN attempts + 1 >= :max THEN 'failed' ELSE 'pending' END
         WHERE id = :id"
      );
      $u->execute([
        ':id'  => $leadId,
        ':err' => substr((string)$errorMsg, 0, 1000),
        ':max' => PUSH_MAX_ATTEMPTS,
      ]);
    }
  } catch (Throwable $e) {
    error_log('lead status update failed: ' . $e->getMessage());
  }

  return $ok;
}

function drain_pending(PDO $pdo, string $url, int $excludeId = 0, int $limit = 5, int $olderThanMinutes = 2): int {
  $stmt = $pdo->prepare(
    "SELECT id, payload FROM leads
     WHERE status = 'pending'
       AND attempts < :max
       AND created_at < (NOW() - INTERVAL :mins MINUTE)
       AND id <> :exclude
     ORDER BY created_at ASC
     LIMIT $limit"
  );
  // Bind LIMIT separately via the SQL itself because PDO MySQL can't bind it as a placeholder
  // when emulation is off. (We still use prepared statements for everything else.)
  $stmt->bindValue(':max', PUSH_MAX_ATTEMPTS, PDO::PARAM_INT);
  $stmt->bindValue(':mins', $olderThanMinutes, PDO::PARAM_INT);
  $stmt->bindValue(':exclude', $excludeId, PDO::PARAM_INT);
  $stmt->execute();

  $count = 0;
  while ($row = $stmt->fetch()) {
    push_lead_to_apps_script($pdo, (int)$row['id'], (string)$row['payload'], $url);
    $count++;
  }
  return $count;
}
