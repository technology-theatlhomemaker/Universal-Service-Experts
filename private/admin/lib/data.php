<?php
declare(strict_types=1);

function metro_path(string $metro): string
{
    return DATA_DIR . '/' . $metro . '.json';
}

function load_metro(string $metro): array
{
    if (!valid_metro($metro)) {
        throw new InvalidArgumentException("Invalid metro: $metro");
    }
    $path = metro_path($metro);
    if (!is_file($path)) {
        throw new RuntimeException("Metro file not found: $path");
    }

    $fp = fopen($path, 'r');
    if (!$fp) {
        throw new RuntimeException("Could not open $path");
    }
    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException("Could not acquire shared lock on $path");
        }
        $contents = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    $data = json_decode((string)$contents, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON in $path: " . json_last_error_msg());
    }
    return $data;
}

function backup_metro(string $metro): string
{
    if (!is_dir(BACKUPS_DIR)) {
        mkdir(BACKUPS_DIR, 0755, true);
    }
    $path = metro_path($metro);
    if (!is_file($path)) {
        return '';
    }
    $stamp  = date('Y-m-d_His');
    $target = BACKUPS_DIR . "/{$metro}-{$stamp}.json";
    copy($path, $target);
    trim_backups($metro, 20);
    return $target;
}

function trim_backups(string $metro, int $keep): void
{
    $files = glob(BACKUPS_DIR . "/{$metro}-*.json") ?: [];
    if (count($files) <= $keep) {
        return;
    }
    sort($files);
    $excess = array_slice($files, 0, count($files) - $keep);
    foreach ($excess as $f) {
        @unlink($f);
    }
}

function save_metro(string $metro, array $data): void
{
    if (!valid_metro($metro)) {
        throw new InvalidArgumentException("Invalid metro: $metro");
    }
    $path = metro_path($metro);

    $data['metro']   = $metro;
    $data['version'] = date('Y-m-d');

    if (isset($data['cities']) && is_array($data['cities'])) {
        usort($data['cities'], static function ($a, $b) {
            return strcmp((string)($a['slug'] ?? ''), (string)($b['slug'] ?? ''));
        });
    }

    $json = pretty_json_encode($data) . "\n";

    backup_metro($metro);

    $fp = fopen($path, 'c+');
    if (!$fp) {
        throw new RuntimeException("Could not open $path for writing");
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException("Could not acquire exclusive lock on $path");
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

function find_city(array $data, string $slug): ?array
{
    foreach ($data['cities'] ?? [] as $i => $city) {
        if (($city['slug'] ?? null) === $slug) {
            return ['index' => $i, 'city' => $city];
        }
    }
    return null;
}

function upsert_city(array &$data, array $city): void
{
    $slug = $city['slug'] ?? '';
    if (!valid_slug($slug)) {
        throw new InvalidArgumentException("Invalid slug: $slug");
    }
    $found = find_city($data, $slug);
    if ($found) {
        $data['cities'][$found['index']] = $city;
    } else {
        $data['cities'][] = $city;
    }
}

function empty_city_stub(string $slug, string $name): array
{
    return [
        'slug'                 => $slug,
        'name'                 => $name,
        'display_name'         => $name,
        'parent_county'        => '',
        'parent_municipality'  => '',
        'zips'                 => [],
        'population'           => null,
        'median_home_age'      => null,
        'homeownership_rate'   => null,
        'notable_landmarks'    => [],
        'vibe'                 => '',
        'service_relevance'    => [
            'electrical' => ['score' => 3, 'why' => ''],
            'hvac'       => ['score' => 3, 'why' => ''],
            'plumbing'   => ['score' => 3, 'why' => ''],
            'handyman'   => ['score' => 3, 'why' => ''],
        ],
        'local_ctas'           => ['', '', '', '', ''],
        'content_hooks'        => ['', ''],
        'seo'                  => [
            'h1'                => '',
            'title_tag'         => '',
            'meta_description'  => '',
            'primary_keywords'  => ['', '', ''],
        ],
        'sources'              => ['', ''],
        'review_notes'         => null,
        'body_copy'            => null,
    ];
}

function page_exists(string $slug): bool
{
    return is_file(REPO_ROOT . '/public_html/' . $slug . '/index.html');
}

/**
 * Pretty-print JSON in the style used by data/service-areas/*.json:
 *   - 2-space indent
 *   - short arrays of scalars render inline:  "zips": ["a", "b", "c"]
 *   - short objects render inline with spaces:  "x": { "k": "v" }
 *   - threshold differs: arrays expand sooner (80) than objects (140), matching the file's hand-edited style
 */
function pretty_json_encode(mixed $value, int $depth = 0): string
{
    $arrayMax  = 80;
    $objectMax = 140;
    $indent      = str_repeat('  ', $depth);
    $childIndent = str_repeat('  ', $depth + 1);

    if (is_array($value)) {
        if ($value === []) {
            return array_is_list($value) ? '[]' : '{}';
        }
        $isList = array_is_list($value);

        $childParts = [];
        $hasNewline = false;
        foreach ($value as $k => $v) {
            $rendered = pretty_json_encode($v, $depth + 1);
            if (str_contains($rendered, "\n")) {
                $hasNewline = true;
            }
            $childParts[] = $isList
                ? $rendered
                : json_encode((string)$k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ': ' . $rendered;
        }

        if (!$hasNewline) {
            $compactInner = implode(', ', $childParts);
            $compact = $isList
                ? '[' . $compactInner . ']'
                : '{ ' . $compactInner . ' }';
            $maxLine = $isList ? $arrayMax : $objectMax;
            if (strlen($indent) + strlen($compact) <= $maxLine) {
                return $compact;
            }
        }

        $body = implode(",\n", array_map(static fn ($p) => $childIndent . $p, $childParts));
        return $isList
            ? "[\n" . $body . "\n" . $indent . ']'
            : "{\n" . $body . "\n" . $indent . '}';
    }

    if ($value === null) return 'null';
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_int($value)) return (string)$value;
    if (is_float($value)) {
        $s = (string)$value;
        return str_contains($s, '.') || str_contains($s, 'e') ? $s : $s . '.0';
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
