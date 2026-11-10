<?php
declare(strict_types=1);

/**
 * Eigenständiger Export des alten Coastal-Blogs (keine OmniWrite-Abhängigkeiten, läuft auf dem Coastal-Hosting).
 *
 *   php coastal-export.php <config.php> <webroot> <ausgabe.json>
 *
 * config.php  = site/_private/config.php von Coastal (nur der Block "db" und "site_url" werden gelesen)
 * webroot     = Verzeichnis, in dem assets/blog/… und assets/slots/… liegen (public_html)
 * Die Datei enthält Beiträge, Themen, Einstellungen, Empfänger und die referenzierten Bilder (base64).
 * Sie enthält KEINE Zugangsdaten. Trotzdem nach dem Import löschen: sie enthält Empfänger-E-Mail-Adressen.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
[$self, $configFile, $webroot, $out] = $argv + [null, null, null, null];
if (!$configFile || !$webroot || !$out || !is_file($configFile) || !is_dir($webroot)) {
    fwrite(STDERR, "Nutzung: php coastal-export.php <config.php> <webroot> <ausgabe.json>\n");
    exit(1);
}
$cfg = require $configFile;
$db = $cfg['db'] ?? [];
if (empty($db['dsn']) && !empty($cfg['db_dsn'])) {
    $db = ['dsn' => $cfg['db_dsn'], 'user' => $cfg['db_user'] ?? null, 'pass' => $cfg['db_pass'] ?? null];
}
if (empty($db['dsn'])) {
    fwrite(STDERR, "In der Konfiguration fehlt der Datenbank-Block.\n");
    exit(1);
}
$pdo = new PDO($db['dsn'], $db['user'] ?? null, $db['pass'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
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
file_put_contents($out, json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
@chmod($out, 0600);
printf("Exportiert: %d Beiträge, %d Themen, %d Empfänger, %d Bilder -> %s\n", count($posts), count($topics), count($recipients), count($images), $out);
