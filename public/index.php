<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OmniWrite\App;
use OmniWrite\Core\Config;
use OmniWrite\Core\Database;
use OmniWrite\Http\HttpsPolicy;
use OmniWrite\Http\Request;

try {
    $config = Config::fromFile(getenv('OMNIWRITE_CONFIG') ?: __DIR__ . '/../config/config.php');
    $baseUrl = (string) $config->get('app.base_url', '');
    $trustProxy = (bool) $config->get('app.trust_forwarded_proto', false);
    $target = HttpsPolicy::redirectTarget($_SERVER, $baseUrl, (bool) $config->get('app.force_https', true), $trustProxy);
    if ($target !== null) {
        header('Location: ' . $target, true, 301);
        exit;
    }
    // Secure-Cookie, sobald die Basis-URL https ist (nicht nur, wenn der Request es zeigt).
    $https = str_starts_with($baseUrl, 'https://') || HttpsPolicy::isSecure($_SERVER, $trustProxy);
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
