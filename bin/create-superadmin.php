<?php
declare(strict_types=1);

// Aufruf: OMNIWRITE_ADMIN_PASSWORD='...' php bin/create-superadmin.php mail@example.com "Name"
require __DIR__ . '/../vendor/autoload.php';

use OmniWrite\App;
use OmniWrite\Core\Config;
use OmniWrite\Core\Database;
use OmniWrite\Domain\ValidationException;

[$script, $email, $name] = $argv + [null, null, 'Superadmin'];
$password = getenv('OMNIWRITE_ADMIN_PASSWORD') ?: '';
if (!$email || $password === '') {
    fwrite(STDERR, "Nutzung: OMNIWRITE_ADMIN_PASSWORD=... php bin/create-superadmin.php <email> [name]\n");
    exit(1);
}
$config = Config::fromFile(getenv('OMNIWRITE_CONFIG') ?: __DIR__ . '/../config/config.php');
try {
    $user = App::buildAuth(Database::connect($config), $config)->createSuperadmin($email, $name, $password);
} catch (ValidationException $e) {
    fwrite(STDERR, 'Fehler: ' . implode(', ', $e->errors) . "\n");
    exit(1);
}
echo "Superadmin angelegt: {$user->email} (id {$user->id})\n";
