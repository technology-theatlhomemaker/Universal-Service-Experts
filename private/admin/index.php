<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/runner.php';
require __DIR__ . '/lib/render.php';

start_session();

$action = (string)($_GET['action'] ?? '');
$view   = (string)($_GET['view'] ?? '');
$metro  = (string)($_GET['metro'] ?? DEFAULT_METRO);
$slug   = (string)($_GET['slug'] ?? '');

if ($action === 'logout') {
    logout();
    header('Location: index.php');
    exit;
}

require_auth();

if (!valid_metro($metro)) {
    http_response_code(400);
    echo 'Invalid metro';
    exit;
}

try {
    $data = load_metro($metro);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to load metro: ' . e($e->getMessage());
    exit;
}

switch ($action) {
    case 'save':
        require_post();
        if (!valid_slug($slug)) {
            http_response_code(400); echo 'Invalid slug'; exit;
        }
        $errors = handle_save($data, $metro, $slug);
        if (empty($errors)) {
            flash_set('ok', "Saved $slug.");
            header('Location: ' . admin_url(['view' => 'edit', 'metro' => $metro, 'slug' => $slug]));
            exit;
        }
        $found = find_city($data, $slug);
        if (!$found) {
            http_response_code(404); echo 'City vanished'; exit;
        }
        $city = $found['city'];
        render_edit($city, $metro, $errors);
        exit;

    case 'add-city':
        require_post();
        $newSlug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $newName = trim((string)($_POST['name'] ?? ''));
        if (!valid_slug($newSlug) || $newName === '') {
            flash_set('err', 'Slug must be lowercase hyphenated; name required.');
            header('Location: ' . admin_url(['metro' => $metro]));
            exit;
        }
        if (find_city($data, $newSlug)) {
            flash_set('err', "Slug $newSlug already exists.");
            header('Location: ' . admin_url(['metro' => $metro]));
            exit;
        }
        $stub = empty_city_stub($newSlug, $newName);
        upsert_city($data, $stub);
        save_metro($metro, $data);
        flash_set('ok', "Added $newSlug. Fill in the fields below.");
        header('Location: ' . admin_url(['view' => 'edit', 'metro' => $metro, 'slug' => $newSlug]));
        exit;

    case 'generate':
        require_post();
        if (!valid_slug($slug)) {
            http_response_code(400); echo 'Invalid slug'; exit;
        }
        $result = run_generator($slug, $metro);
        $out = trim($result['stdout'] . "\n" . $result['stderr']);
        if ($result['ok']) {
            flash_set('ok', "Generated /public_html/$slug/index.html\n\n" . $out);
        } else {
            flash_set('err', "Generator failed (rc={$result['rc']}):\n\n" . $out);
        }
        header('Location: ' . admin_url(['view' => 'edit', 'metro' => $metro, 'slug' => $slug]));
        exit;

    case 'run-agent':
        if (!valid_slug($slug)) {
            http_response_code(400); echo 'Invalid slug'; exit;
        }
        stream_agent($slug);
        exit;

    case 'agent-stream-page':
        if (!valid_slug($slug)) {
            http_response_code(400); echo 'Invalid slug'; exit;
        }
        require __DIR__ . '/views/agent-stream.php';
        exit;
}

if ($view === 'edit') {
    if (!valid_slug($slug)) {
        http_response_code(400); echo 'Invalid slug'; exit;
    }
    $found = find_city($data, $slug);
    if (!$found) {
        http_response_code(404);
        flash_set('err', "City $slug not found.");
        header('Location: ' . admin_url(['metro' => $metro]));
        exit;
    }
    render_edit($found['city'], $metro, []);
    exit;
}

require __DIR__ . '/views/list.php';

// ----- handlers -----------------------------------------------------------

function handle_save(array &$data, string $metro, string $slug): array
{
    $found = find_city($data, $slug);
    if (!$found) {
        return [['slug' => null, 'field' => null, 'message' => "City $slug not found"]];
    }
    $city = $found['city'];
    $city = apply_post_to_city($city);
    $data['cities'][$found['index']] = $city;

    save_metro($metro, $data);

    $result = run_validator($metro);
    if ($result['ok']) {
        return [];
    }
    $allErrors = parse_validator_errors($result['stdout'] . "\n" . $result['stderr']);
    $cityErrors = array_filter(
        $allErrors,
        static fn ($e) => ($e['slug'] ?? null) === $slug || ($e['slug'] ?? null) === null
    );
    return array_values($cityErrors);
}

function apply_post_to_city(array $city): array
{
    $p = $_POST;

    $city['name']                = trim((string)($p['name'] ?? $city['name'] ?? ''));
    $city['display_name']        = trim((string)($p['display_name'] ?? $city['display_name'] ?? ''));
    $city['parent_county']       = trim((string)($p['parent_county'] ?? ''));
    $city['parent_municipality'] = trim((string)($p['parent_municipality'] ?? ''));
    $city['vibe']                = trim((string)($p['vibe'] ?? ''));

    $city['population']         = nullable_number($p['population'] ?? null);
    $city['median_home_age']    = nullable_number($p['median_home_age'] ?? null);
    $city['homeownership_rate'] = nullable_float($p['homeownership_rate'] ?? null);

    $city['zips']              = clean_strings($p['zips'] ?? []);
    sort($city['zips']);
    $city['notable_landmarks'] = clean_strings($p['notable_landmarks'] ?? []);
    $city['local_ctas']        = clean_strings($p['local_ctas'] ?? [], false);
    $city['content_hooks']     = clean_strings($p['content_hooks'] ?? []);
    $city['sources']           = clean_strings($p['sources'] ?? []);

    foreach (['electrical', 'hvac', 'plumbing', 'handyman'] as $svc) {
        $city['service_relevance'][$svc] = [
            'score' => max(1, min(5, (int)($p['sr_score'][$svc] ?? 3))),
            'why'   => trim((string)($p['sr_why'][$svc] ?? '')),
        ];
    }

    $city['seo'] = [
        'h1'                => trim((string)($p['seo_h1'] ?? '')),
        'title_tag'         => trim((string)($p['seo_title_tag'] ?? '')),
        'meta_description'  => trim((string)($p['seo_meta_description'] ?? '')),
        'primary_keywords'  => clean_strings($p['seo_primary_keywords'] ?? []),
    ];

    $reviewNotes = trim((string)($p['review_notes'] ?? ''));
    $city['review_notes'] = $reviewNotes === '' ? null : $reviewNotes;

    if (!array_key_exists('body_copy', $city)) {
        $city['body_copy'] = null;
    }

    return $city;
}

function clean_strings(mixed $arr, bool $dropEmpty = true): array
{
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $v) {
        if (!is_string($v)) continue;
        $v = trim($v);
        if ($dropEmpty && $v === '') continue;
        $out[] = $v;
    }
    return $out;
}

function nullable_number(mixed $v): ?int
{
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    return (int)$s;
}

function nullable_float(mixed $v): ?float
{
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    return (float)$s;
}

function render_edit(array $city, string $metro, array $errors): void
{
    require __DIR__ . '/views/edit.php';
}
