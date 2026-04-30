<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function score_label(int $score): string
{
    return match ($score) {
        1       => '1 — low',
        2       => '2',
        3       => '3 — moderate',
        4       => '4',
        5       => '5 — high',
        default => (string)$score,
    };
}

function word_count(?string $s): int
{
    if ($s === null || trim($s) === '') {
        return 0;
    }
    return count(preg_split('/\s+/', trim($s)) ?: []);
}

function header_html(string $title, ?string $metro = null): void
{
    $metro = $metro ?? DEFAULT_METRO;
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($title) ?> — USE Admin</title>
  <link rel="stylesheet" href="assets/admin.css" />
</head>
<body>
  <header class="topbar">
    <div class="topbar-inner">
      <a class="brand" href="index.php">USE Admin</a>
      <nav>
        <a href="<?= e(admin_url(['view' => 'list', 'metro' => $metro])) ?>">Cities</a>
        <a href="<?= e(admin_url(['action' => 'logout'])) ?>" class="logout">Log out</a>
      </nav>
    </div>
  </header>
  <main class="container">
    <?php $flash = flash_take(); if ($flash): ?>
      <div class="flash flash-<?= e($flash['kind']) ?>"><?= nl2br(e($flash['message'])) ?></div>
    <?php endif; ?>
    <?php
}

function footer_html(): void
{
    ?>
  </main>
  <script src="assets/admin.js" defer></script>
</body>
</html>
    <?php
}

function field_error(array $errors, string $field): ?string
{
    foreach ($errors as $err) {
        $f = $err['field'] ?? '';
        if ($f === $field || str_starts_with($f, $field . '.') || str_starts_with($f, $field . '[')) {
            return $err['message'];
        }
    }
    return null;
}

function global_errors(array $errors): array
{
    $out = [];
    foreach ($errors as $err) {
        if (empty($err['field']) && empty($err['slug'])) {
            $out[] = $err['message'];
        }
    }
    return $out;
}
