<?php
declare(strict_types=1);

// Einmalige Einrichtungsseite (Migrationen + erster Superadmin). Aktiv nur mit gesetztem `setup.token` in config/config.php.
require __DIR__ . '/../vendor/autoload.php';

use OmniWrite\Core\Config;
use OmniWrite\Core\Database;
use OmniWrite\Http\HttpsPolicy;
use OmniWrite\Setup\SetupPage;

try {
    $config = Config::fromFile(getenv('OMNIWRITE_CONFIG') ?: __DIR__ . '/../config/config.php');
    $target = HttpsPolicy::redirectTarget($_SERVER, (string) $config->get('app.base_url', ''), (bool) $config->get('app.force_https', true), (bool) $config->get('app.trust_forwarded_proto', false));
    if ($target !== null) {
        header('Location: ' . $target, true, 301);
        exit;
    }
    $page = new SetupPage($config, static fn () => Database::connect($config), __DIR__ . '/../migrations');
    $res = $page->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_POST);
    http_response_code($res['status']);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    echo $res['html'];
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Internal error';
}
