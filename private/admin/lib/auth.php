<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('use_admin');
    session_start();
}

function is_authed(): bool
{
    return !empty($_SESSION['authed']);
}

function attempt_login(string $token): bool
{
    $expected = admin_token();
    if ($expected === '' || !hash_equals($expected, $token)) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['authed']   = true;
    $_SESSION['login_at'] = time();
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

function require_auth(): void
{
    if (!is_authed()) {
        $token = (string)($_GET['token'] ?? '');
        if ($token !== '' && attempt_login($token)) {
            $params = $_GET;
            unset($params['token']);
            $url = 'index.php' . (empty($params) ? '' : '?' . http_build_query($params));
            header('Location: ' . $url);
            exit;
        }
        render_login();
        exit;
    }
}

function render_login(): void
{
    require ADMIN_DIR . '/views/login.php';
}
