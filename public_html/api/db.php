<?php
declare(strict_types=1);

function use_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $path = __DIR__ . '/../../private/secrets.php';
  if (!is_file($path)) {
    throw new RuntimeException('private/secrets.php not deployed');
  }
  $cfg = require $path;
  if (!is_array($cfg)) {
    throw new RuntimeException('secrets.php must return an array');
  }
  return $cfg;
}

function use_db(): PDO {
  static $pdo = null;
  if ($pdo !== null) return $pdo;

  $cfg = use_config();
  foreach (['db_host', 'db_name', 'db_user'] as $key) {
    if (empty($cfg[$key])) {
      throw new RuntimeException("secrets.php missing $key");
    }
  }

  $dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    $cfg['db_host'],
    $cfg['db_name']
  );
  $pdo = new PDO($dsn, $cfg['db_user'], (string)($cfg['db_pass'] ?? ''), [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
  return $pdo;
}
