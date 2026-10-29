<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/InventoryService.php';

function load_environment(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

load_environment(dirname(__DIR__) . '/.env');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (getenv('APP_SECURE_COOKIES') === 'true');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function app_db(): PDO
{
    static $db;
    if (!$db instanceof PDO) {
        $db = Database::connect();
    }

    return $db;
}

function current_user(bool $required = true): ?array
{
    static $loaded = false;
    static $user = null;
    if (!$loaded) {
        $user = Auth::currentUser(app_db());
        $loaded = true;
    }
    if ($required && $user === null) {
        header('Location: ?page=login');
        exit;
    }

    return $user;
}

function require_role(array $roles): array
{
    $user = current_user();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        throw new RuntimeException('Your account is not authorized to perform this action.');
    }

    return $user;
}

function redirect(string $page = 'dashboard'): never
{
    header('Location: ?page=' . rawurlencode($page));
    exit;
}
