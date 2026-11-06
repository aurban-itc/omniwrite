<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OmniWrite\Core\Config;
use OmniWrite\Core\Database;
use OmniWrite\Core\Migrator;

$config = Config::fromFile(getenv('OMNIWRITE_CONFIG') ?: __DIR__ . '/../config/config.php');
$applied = (new Migrator(Database::connect($config), __DIR__ . '/../migrations'))->migrate();
echo $applied === [] ? "Nichts zu tun.\n" : "Angewendet:\n  " . implode("\n  ", $applied) . "\n";
