<?php
declare(strict_types=1);

// Aufruf: php bin/import-coastal.php <site-slug> <export.json>   (Export: tools/coastal-export.php auf dem Coastal-Hosting)
require __DIR__ . '/../vendor/autoload.php';

use OmniWrite\Core\Config;
use OmniWrite\Core\Database;
use OmniWrite\Import\CoastalImporter;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Support\ImageStore;

[$script, $slug, $file] = $argv + [null, null, null];
if (!$slug || !$file || !is_file($file)) {
    fwrite(STDERR, "Nutzung: php bin/import-coastal.php <site-slug> <export.json>\n");
    exit(1);
}
$root = dirname(__DIR__);
$config = Config::fromFile(getenv('OMNIWRITE_CONFIG') ?: $root . '/config/config.php');
$pdo = Database::connect($config);
$site = (new SiteRepository($pdo))->findBySlug($slug);
if ($site === null) {
    fwrite(STDERR, "Site '$slug' existiert nicht. Zuerst im Portal anlegen.\n");
    exit(1);
}
$bundle = json_decode((string) file_get_contents($file), true);
if (!is_array($bundle)) {
    fwrite(STDERR, "Die Datei ist kein gültiger Export.\n");
    exit(1);
}
try {
    $r = (new CoastalImporter($pdo, new ImageStore((string) $config->get('storage.path', $root . '/storage'))))->import((int) $site['id'], $bundle);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
printf("Beiträge: %d neu, %d vorhanden | Themen: %d neu, %d vorhanden | Empfänger: %d neu, %d vorhanden | Bilder: %d (in %d vorhandenen Beiträgen nachgetragen)\n",
    $r['posts'], $r['posts_skipped'], $r['topics'], $r['topics_skipped'], $r['recipients'], $r['recipients_skipped'], $r['images'], $r['images_repaired']);
foreach ($r['warnings'] as $w) {
    echo "Hinweis: $w\n";
}
