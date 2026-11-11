<?php
declare(strict_types=1);

/**
 * Eigenständiger Export des alten Coastal-Blogs (keine OmniWrite-Abhängigkeiten, läuft auf dem Coastal-Hosting).
 *
 * 1) Kommandozeile:  php coastal-export.php <config.php> <webroot> <ausgabe.json>
 *      config.php = site/_private/config.php von Coastal (nur "db" und "site_url" werden gelesen)
 *      webroot    = Verzeichnis, in dem assets/blog/… und assets/slots/… liegen (public_html)
 *
 * 2) Browser, nur vorübergehend (wenn weder SSH noch Cron gehen): Datei in public_html legen, unten WEB_TOKEN auf
 *    einen langen Zufallswert setzen und https://<domain>/coastal-export.php?t=<WEB_TOKEN> aufrufen. Der Download startet,
 *    danach löscht die Datei sich selbst. Liegt die Datei in einem Unterordner (z. B. api/), wird der darüberliegende Ordner als Webroot genommen.
 *    Coastals .htaccess sperrt PHP außer submit.php: dort in api/ zusätzlich eine .htaccess mit <Files "coastal-export.php"> Require all granted </Files> anlegen (beides danach löschen). Ohne gesetzten Token tut der Browser-Aufruf nichts. Bei Fehlern die Datei sofort löschen.
 *
 * Die Ausgabe enthält Beiträge (auch Entwürfe), Themen, Einstellungen, Empfänger und die verwendeten Bilder (base64), aber KEINE
 * Zugangsdaten. Nach dem Import überall löschen: sie enthält Empfänger-E-Mail-Adressen.
 */
const WEB_TOKEN = '';   // nur für den Browser-Aufruf: mindestens 24 zufällige Zeichen, danach wieder entfernen

/** @return array<string, mixed> */
function coastal_export(string $configFile, string $webroot): array
{
    if (!is_file($configFile) || !is_dir($webroot)) {
        throw new RuntimeException('config.php oder Webroot nicht gefunden.');
    }
    $cfg = require $configFile;
    $db = $cfg['db'] ?? [];
    if (empty($db['dsn']) && !empty($cfg['db_dsn'])) {
        $db = ['dsn' => $cfg['db_dsn'], 'user' => $cfg['db_user'] ?? null, 'pass' => $cfg['db_pass'] ?? null];
    }
    if (empty($db['dsn'])) {
        throw new RuntimeException('In der Konfiguration fehlt der Datenbank-Block.');
    }
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    try {
        $pdo = new PDO($db['dsn'], $db['user'] ?? null, $db['pass'] ?? null, $opts);
    } catch (PDOException $e) {
        // Wie im Coastal-Blog: manche Hoster legen DB-Nutzer nur für 'localhost' (Socket) an, nicht für 127.0.0.1.
        if (!str_contains($db['dsn'], 'host=127.0.0.1')) {
            throw $e;
        }
        $pdo = new PDO(str_replace('host=127.0.0.1', 'host=localhost', $db['dsn']), $db['user'] ?? null, $db['pass'] ?? null, $opts);
    }
    $origin = rtrim((string) ($cfg['site_url'] ?? ''), '/');
    if ($origin === '' || preg_match('#^https?://[^/]*\.hostingersite\.com(/|$)#i', $origin)) {
        $origin = 'https://www.coastalcopy.com';
    }

    $rows = static fn (string $sql): array => $pdo->query($sql)->fetchAll();
    $posts = $rows('SELECT slug, title, excerpt, body_html, category, keywords, meta_description, status, cover_image, cover_alt, image_prompt, read_minutes, legacy_slug, published_at, created_at, updated_at, reviewed_by, reviewed_at, review_note, topic_id FROM cb_posts ORDER BY id');
    $topics = $rows('SELECT id, title, keywords, category, notes, status, sort_order, times_used, last_used_at FROM cb_topics ORDER BY sort_order, id');
    $recipients = $rows('SELECT email, name, active FROM cb_recipients ORDER BY id');
    $settings = [];
    foreach ($rows('SELECT k, v FROM cb_settings') as $r) {
        // Allowlist statt Sperrliste: nur diese Inhalte und der Themenzeiger gehören in die Datei, alles andere (auch unbekannte Schlüssel) bleibt zurück.
        if (in_array($r['k'], ['style_guide', 'company_facts', 'image_style', 'topic_cursor'], true)) {
            $settings[$r['k']] = $r['v'];
        }
    }

    // Referenzierte Bilder einsammeln (Titelbild + <img> im Text), nur unterhalb von assets/.
    $images = [];
    $collect = static function (string $ref) use (&$images, $webroot, $origin): void {
        $path = ltrim((string) preg_replace('#^' . preg_quote($origin, '#') . '#', '', $ref), '/');
        if (!str_starts_with($path, 'assets/') || str_contains($path, '..') || isset($images[$path])) {
            return;
        }
        $file = $webroot . '/' . $path;
        if (is_file($file) && filesize($file) <= 8 * 1024 * 1024) {
            $images[$path] = base64_encode((string) file_get_contents($file));
        }
    };
    foreach ($posts as $p) {
        $collect((string) $p['cover_image']);
        if (preg_match_all('#<img[^>]+src=["\']([^"\']+)["\']#i', (string) $p['body_html'], $m)) {
            array_map($collect, $m[1]);
        }
    }

    $bundle = [
        'format' => 'coastal-export/1',
        'exported_at' => gmdate('c'),
        'origin' => $origin,
        'posts' => $posts,
        'topics' => $topics,
        'recipients' => $recipients,
        'settings' => $settings,
        'images' => $images,
    ];

    return $bundle;
}

if (PHP_SAPI === 'cli') {
    if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
        return;
    }
    [$self, $configFile, $webroot, $out] = $argv + [null, null, null, null];
    if (!$configFile || !$webroot || !$out) {
        fwrite(STDERR, "Nutzung: php coastal-export.php <config.php> <webroot> <ausgabe.json>\n");
        exit(1);
    }
    try {
        $bundle = coastal_export($configFile, $webroot);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
        exit(1);
    }
    file_put_contents($out, json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    @chmod($out, 0600);
    printf("Exportiert: %d Beiträge, %d Themen, %d Empfänger, %d Bilder -> %s\n", count($bundle['posts']), count($bundle['topics']), count($bundle['recipients']), count($bundle['images']), $out);
    return;
}

// Browser-Aufruf: nur mit gesetztem Token, genau einmal.
if (WEB_TOKEN === '' || strlen(WEB_TOKEN) < 24) {
    http_response_code(404);
    exit;
}
$given = (string) ($_GET['t'] ?? '');
if (!hash_equals(WEB_TOKEN, $given)) {
    sleep(2);
    http_response_code(404);
    exit;
}
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$config = null;
foreach ([__DIR__ . '/_private/config.php', dirname(__DIR__) . '/_private/config.php'] as $c) {
    if (is_file($c)) {
        $config = $c;
        break;
    }
}
try {
    if ($config === null) {
        throw new RuntimeException('_private/config.php nicht gefunden.');
    }
    $json = json_encode(coastal_export($config, is_dir(__DIR__ . '/assets') ? __DIR__ : dirname(__DIR__)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    error_log('coastal-export.php: ' . $e->getMessage());     // Details nur ins Fehlerlog, nicht an den Browser
    echo 'Export fehlgeschlagen. Bitte die Datei löschen und den Fehler im Server-Log prüfen.';
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="export.json"');
header('Content-Length: ' . strlen($json));
echo $json;
if (!@unlink(__FILE__)) {
    error_log('coastal-export.php konnte sich nicht selbst löschen: bitte manuell entfernen');
}
