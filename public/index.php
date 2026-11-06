<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OmniWrite\App;
use OmniWrite\Core\Config;
use OmniWrite\Core\Database;
use OmniWrite\Http\Request;

try {
    $config = Config::fromFile(getenv('OMNIWRITE_CONFIG') ?: __DIR__ . '/../config/config.php');
    // Secure-Cookie, sobald die konfigurierte Basis-URL https ist (nicht nur, wenn der Request es zeigt).
    $https = str_starts_with((string) $config->get('app.base_url', ''), 'https://') || ($_SERVER['HTTPS'] ?? '') === 'on';
    session_name('omniwrite');
    session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
    $kernel = App::kernel(Database::connect($config), $config);
    $kernel->handle(Request::fromGlobals($_SESSION))->send();
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Internal error';
}
