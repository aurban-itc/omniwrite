<?php
declare(strict_types=1);

// Stündlicher Cron (die Serverzeitzone ist egal, jede Site wird in ihrer eigenen Zeitzone geprüft):
//   0 * * * *  php /pfad/zu/omniwrite/bin/cron.php
// Erzeugt für fällige Sites (Zeitplan eingeschaltet) einen Entwurf und schickt die Review-Mail. Veröffentlicht wird nie automatisch.
// Danach werden veröffentlichte Beiträge mit den Push-Zielen (Webhook, GitHub) abgeglichen.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

use OmniWrite\App;
use OmniWrite\Core\Config;
use OmniWrite\Core\Database;

set_time_limit(900);
try {
    $config = Config::fromFile(getenv('OMNIWRITE_CONFIG') ?: __DIR__ . '/../config/config.php');
    $pdo = Database::connect($config);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $results = App::weeklyRunner($pdo, $config)->runDue($now);
    // Push-Ziele (Webhook, GitHub): auch wenn der Wochenlauf nichts tat, ggf. neu Veröffentlichtes zustellen.
    $pushLines = App::pushService($pdo, $config)->syncAll($now);
} catch (Throwable $e) {
    error_log((string) $e);
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
$failed = false;
foreach ($results as $r) {
    echo gmdate('c') . ' ' . $r['site'] . ' ' . $r['status'] . ': ' . $r['message'] . "\n";
    $failed = $failed || $r['status'] === 'failed';
}
foreach ($pushLines as $line) {
    echo gmdate('c') . ' push ' . $line . "\n";
}
exit($failed ? 1 : 0);
