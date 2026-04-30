<?php
declare(strict_types=1);

const ADMIN_DIR = __DIR__ . '/..';
const REPO_ROOT = __DIR__ . '/../../..';
const SECRETS_PATH = REPO_ROOT . '/private/secrets.php';
const DATA_DIR = REPO_ROOT . '/data/service-areas';
const BACKUPS_DIR = ADMIN_DIR . '/backups';
const VALID_SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
const VALID_METRO = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
const DEFAULT_METRO = 'atlanta';

function load_secrets(): array
{
    if (!is_file(SECRETS_PATH)) {
        return [];
    }
    $secrets = require SECRETS_PATH;
    return is_array($secrets) ? $secrets : [];
}

function admin_token(): string
{
    return (string)(load_secrets()['admin_token'] ?? '');
}

function flash_set(string $kind, string $message): void
{
    $_SESSION['flash'] = ['kind' => $kind, 'message' => $message];
}

function flash_take(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function admin_url(array $params = []): string
{
    if (empty($params)) {
        return 'index.php';
    }
    return 'index.php?' . http_build_query($params);
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        exit;
    }
}

function valid_slug(string $slug): bool
{
    return $slug !== '' && (bool)preg_match(VALID_SLUG, $slug);
}

function valid_metro(string $metro): bool
{
    return $metro !== '' && (bool)preg_match(VALID_METRO, $metro);
}
