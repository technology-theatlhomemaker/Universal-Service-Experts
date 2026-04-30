<?php
declare(strict_types=1);

function which(string $cmd): ?string
{
    $output = [];
    $rc = 0;
    @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $output, $rc);
    if ($rc === 0 && !empty($output[0])) {
        return $output[0];
    }
    return null;
}

function run_validator(string $metro): array
{
    $script = REPO_ROOT . '/scripts/validate-service-areas.mjs';
    $data   = REPO_ROOT . '/data/service-areas/' . $metro . '.json';
    $node   = which('node');
    if ($node === null) {
        return ['ok' => false, 'rc' => -1, 'stdout' => '', 'stderr' => 'node not found on PATH'];
    }
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($data);
    return run_capture($cmd, REPO_ROOT);
}

function run_generator(string $slug, string $metro): array
{
    $script = REPO_ROOT . '/scripts/generate-city-pages.mjs';
    $node   = which('node');
    if ($node === null) {
        return ['ok' => false, 'rc' => -1, 'stdout' => '', 'stderr' => 'node not found on PATH'];
    }
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($script)
         . ' --city ' . escapeshellarg($slug)
         . ' --metro ' . escapeshellarg($metro);
    return run_capture($cmd, REPO_ROOT);
}

function run_capture(string $cmd, string $cwd): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        return ['ok' => false, 'rc' => -1, 'stdout' => '', 'stderr' => "Could not start: $cmd"];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    return [
        'ok'     => $rc === 0,
        'rc'     => $rc,
        'stdout' => (string)$stdout,
        'stderr' => (string)$stderr,
    ];
}

function parse_validator_errors(string $stdout): array
{
    $errors = [];
    $lines = preg_split('/\r?\n/', $stdout);
    foreach ($lines as $line) {
        if (!preg_match('/^\s*[•·]\s*(.*)$/u', $line, $m)) {
            continue;
        }
        $msg = trim($m[1]);
        $slug  = null;
        $field = null;
        if (preg_match('/^cities\[\d+\](?:\s+\(([^)]+)\))?:\s*(.+)$/', $msg, $cm)) {
            $slug = $cm[1] ?? null;
            $rest = $cm[2];
            if (preg_match('/^([a-z_][a-z0-9_.\[\]]*)\b/i', $rest, $fm)) {
                $field = $fm[1];
            }
            $errors[] = ['slug' => $slug, 'field' => $field, 'message' => $rest];
        } else {
            $errors[] = ['slug' => null, 'field' => null, 'message' => $msg];
        }
    }
    return $errors;
}

function stream_agent(string $slug): void
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $claude = which('claude');
    if ($claude === null) {
        echo "ERROR: claude CLI not found on PATH.\n";
        echo "Install Claude Code first: https://claude.com/claude-code\n";
        return;
    }

    $prompt = "Use the city-content-writer agent for $slug";
    $cmd = 'cd ' . escapeshellarg(REPO_ROOT) . ' && '
         . escapeshellarg($claude) . ' -p ' . escapeshellarg($prompt) . ' 2>&1';

    echo "▶ Running: claude -p \"$prompt\"\n";
    echo str_repeat('─', 60) . "\n";
    @flush();

    $proc = popen($cmd, 'r');
    if (!is_resource($proc)) {
        echo "ERROR: Could not start claude process.\n";
        return;
    }
    while (!feof($proc)) {
        $chunk = fread($proc, 1024);
        if ($chunk === false) break;
        echo $chunk;
        @flush();
    }
    $rc = pclose($proc);

    echo "\n" . str_repeat('─', 60) . "\n";
    echo $rc === 0
        ? "✓ Done. Reload the edit page to see updated body_copy.\n"
        : "✗ Exit code $rc\n";
}
