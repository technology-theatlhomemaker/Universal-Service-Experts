<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/data.php';
require __DIR__ . '/../lib/runner.php';

$metro = $argv[1] ?? 'atlanta';

echo "== Round-trip $metro.json ==\n";

$path = metro_path($metro);
$before = file_get_contents($path);
$data = load_metro($metro);
echo "  loaded: " . count($data['cities']) . " cities, version " . $data['version'] . "\n";

save_metro($metro, $data);
$after = file_get_contents($path);

$beforeJson = json_decode($before, true);
$afterJson  = json_decode($after, true);

$beforeJson['version'] = '__PLACEHOLDER__';
$afterJson['version']  = '__PLACEHOLDER__';

if (json_encode($beforeJson) === json_encode($afterJson)) {
    echo "  ✓ Content-equal (only version differs)\n";
} else {
    echo "  ✗ Content drift detected!\n";
    exit(1);
}

echo "== Validator on saved file ==\n";
$result = run_validator($metro);
echo $result['stdout'];
if (!$result['ok']) {
    echo "  ✗ Validator failed (rc={$result['rc']})\n";
    echo $result['stderr'];
    exit(1);
}
echo "  ✓ Validator passed\n";

echo "\n== Backup created ==\n";
$backups = glob(BACKUPS_DIR . "/{$metro}-*.json") ?: [];
echo "  " . count($backups) . " backup(s) on disk\n";
if (count($backups) === 0) {
    echo "  ✗ No backup created\n";
    exit(1);
}
echo "  ✓ " . basename(end($backups)) . "\n";

echo "\n== Validator error parser ==\n";
$sample = "❌ Validation failed (3 issues):\n\n" .
          "  • cities[3] (buckhead): seo.title_tag is 67 chars; max 60\n" .
          "  • cities[3] (buckhead): zip \"99999\" already assigned to \"alpharetta\"\n" .
          "  • top-level \"version\" must be an ISO date (YYYY-MM-DD); got \"oops\"\n";
$parsed = parse_validator_errors($sample);
foreach ($parsed as $err) {
    printf("  [slug=%s field=%s] %s\n",
        $err['slug'] ?? '∅', $err['field'] ?? '∅', $err['message']);
}
if (count($parsed) !== 3) {
    echo "  ✗ Expected 3 parsed errors, got " . count($parsed) . "\n";
    exit(1);
}
echo "  ✓ Parsed 3 errors\n";

echo "\nAll checks passed.\n";
