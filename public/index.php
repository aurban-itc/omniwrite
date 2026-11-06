<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OmniWrite\Http\Kernel;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
(new Kernel())->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path)->send();
