<?php
declare(strict_types=1);

namespace OmniWrite\Import;

use InvalidArgumentException;
use OmniWrite\Domain\SiteContext;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\RecipientRepository;
use OmniWrite\Repository\SiteSettingsRepository;
use OmniWrite\Repository\TopicRepository;
use OmniWrite\Service\TopicService;
use OmniWrite\Support\Html;
use OmniWrite\Support\ImageStore;
use PDO;
use Throwable;

/**
 * Spielt den Export des alten Coastal-Blogs (tools/coastal-export.php) in eine bestehende Site ein.
 * Wiederholbar: vorhandene Beiträge (Slug), Themen (Titel) und Empfänger (E-Mail) werden übersprungen.
 * Importierte Beiträge behalten ihren Status; nichts wird dabei veröffentlicht, was nicht schon veröffentlicht war.
 */
final class CoastalImporter
{
    private const STATUS_MAP = ['draft' => 'draft', 'review' => 'in_review', 'in_review' => 'in_review', 'published' => 'published', 'declined' => 'declined'];
    /** Einstellungen, die übernommen werden (Inhalte und Themenzeiger; keine Schlüssel). */
    private const SETTINGS = ['style_guide', 'company_facts', 'image_style', 'topic_cursor'];

    public function __construct(private readonly PDO $pdo, private readonly ImageStore $images)
    {
    }

    /** @param array<string, mixed> $bundle @return array<string, int|list<string>> Zähler und Warnungen */
    public function import(int $siteId, array $bundle): array
    {
        if (($bundle['format'] ?? '') !== 'coastal-export/1') {
            throw new InvalidArgumentException('Unbekanntes Exportformat.');
        }
        $origin = rtrim((string) ($bundle['origin'] ?? ''), '/');
        if (!preg_match('#^https://[^/\s]+$#', $origin)) {
            throw new InvalidArgumentException('Der Export enthält keine gültige https-Herkunft.');
        }
        $ctx = new SiteContext($siteId);
        $posts = new PostRepository($this->pdo, $ctx);
        $topics = new TopicRepository($this->pdo, $ctx);
        $recipients = new RecipientRepository($this->pdo, $ctx);
        $settings = new SiteSettingsRepository($this->pdo, $ctx);
        $r = ['posts' => 0, 'posts_skipped' => 0, 'topics' => 0, 'topics_skipped' => 0, 'recipients' => 0, 'recipients_skipped' => 0, 'images' => 0, 'images_repaired' => 0, 'warnings' => []];
        $imageMap = [];

        // Themen zuerst: Beiträge verweisen darauf. Alt-ID => neue ID.
        $topicIds = [];
        $existingTopics = [];
        foreach ($topics->all(10000) as $t) {
            $existingTopics[mb_strtolower((string) $t['title'])] = (int) $t['id'];
        }
        $byOrder = [];
        foreach ((array) ($bundle['topics'] ?? []) as $t) {
            $title = trim((string) ($t['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $key = mb_strtolower($title);
            if (isset($existingTopics[$key])) {
                $topicIds[(int) ($t['id'] ?? 0)] = $existingTopics[$key];
                $byOrder[(int) ($t['sort_order'] ?? 0)] = $existingTopics[$key];
                $r['topics_skipped']++;
                continue;
            }
            $id = $topics->insert([
                'title' => mb_substr($title, 0, 255),
                'keywords' => mb_substr((string) ($t['keywords'] ?? ''), 0, 400),
                'category' => mb_substr((string) ($t['category'] ?? ''), 0, 80),
                'notes' => ($t['notes'] ?? null) === null ? null : (string) $t['notes'],
                'status' => in_array($t['status'] ?? '', TopicRepository::STATUSES, true) ? $t['status'] : 'active',
                'sort_order' => (int) ($t['sort_order'] ?? 0),
                'times_used' => max(0, (int) ($t['times_used'] ?? 0)),
                'last_used_at' => $this->date($t['last_used_at'] ?? null),
            ]);
            $existingTopics[$key] = $id;
            $topicIds[(int) ($t['id'] ?? 0)] = $id;
            $byOrder[(int) ($t['sort_order'] ?? 0)] = $id;
            $r['topics']++;
        }

        foreach ((array) ($bundle['posts'] ?? []) as $p) {
            $slug = trim((string) ($p['slug'] ?? ''));
            $title = trim((string) ($p['title'] ?? ''));
            if ($slug === '' || $title === '') {
                $r['warnings'][] = 'Beitrag ohne Slug oder Titel übersprungen.';
                continue;
            }
            $existing = $posts->findBySlug($slug);
            if ($existing !== null) {
                $r['posts_skipped']++;
                $this->repairImages($posts, $existing, $siteId, $origin, (array) ($bundle['images'] ?? []), $imageMap, $r);
                continue;
            }
            $cover = $this->resolveImage($siteId, (string) ($p['cover_image'] ?? ''), $origin, (array) ($bundle['images'] ?? []), $imageMap, $r);
            $body = $this->rewriteBody($siteId, (string) ($p['body_html'] ?? ''), $origin, (array) ($bundle['images'] ?? []), $imageMap, $r);
            $status = self::STATUS_MAP[(string) ($p['status'] ?? 'draft')] ?? 'draft';
            $posts->insert([
                'title' => mb_substr($title, 0, 255),
                'slug' => mb_substr($slug, 0, 190),
                'body_html' => Html::sanitize($body),
                'status' => $status,
                'excerpt' => mb_substr((string) ($p['excerpt'] ?? ''), 0, 600),
                'category' => mb_substr((string) ($p['category'] ?? ''), 0, 80),
                'keywords' => mb_substr((string) ($p['keywords'] ?? ''), 0, 400),
                'meta_description' => mb_substr((string) ($p['meta_description'] ?? ''), 0, 320),
                'cover_image' => $cover,
                'cover_alt' => mb_substr((string) ($p['cover_alt'] ?? ''), 0, 255),
                'image_prompt' => mb_substr((string) ($p['image_prompt'] ?? ''), 0, 1000),
                'read_minutes' => max(1, (int) ($p['read_minutes'] ?? 4)),
                'topic_id' => $topicIds[(int) ($p['topic_id'] ?? 0)] ?? null,
                'published_at' => $status === 'published' ? ($this->date($p['published_at'] ?? null) ?? $this->date($p['created_at'] ?? null)) : null,
                'updated_at' => $this->date($p['updated_at'] ?? null),
                'reviewed_by' => ($p['reviewed_by'] ?? null) === null ? null : mb_substr((string) $p['reviewed_by'], 0, 190),
                'reviewed_at' => $this->date($p['reviewed_at'] ?? null),
                'review_note' => ($p['review_note'] ?? null) === null ? null : mb_substr((string) $p['review_note'], 0, 600),
            ]);
            $r['posts']++;
        }

        foreach ((array) ($bundle['recipients'] ?? []) as $rc) {
            $email = strtolower(trim((string) ($rc['email'] ?? '')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            if ($recipients->emailTaken($email)) {
                $r['recipients_skipped']++;
                continue;
            }
            $recipients->insert(['email' => $email, 'name' => mb_substr((string) ($rc['name'] ?? ''), 0, 120), 'active' => (int) ($rc['active'] ?? 1) === 1 ? 1 : 0]);
            $r['recipients']++;
        }

        // Einstellungen nur setzen, wo die neue Site noch nichts hat (Handarbeit in OmniWrite gewinnt).
        $bs = (array) ($bundle['settings'] ?? []);
        $cursorImported = false;
        foreach (self::SETTINGS as $key) {
            if (isset($bs[$key]) && $settings->get($key) === '') {
                $settings->set($key, (string) $bs[$key]);
                $cursorImported = $cursorImported || $key === 'topic_cursor';
            }
        }
        // Zeiger-ID nur zusammen mit dem Zeiger übernehmen, sonst beschreiben beide verschiedene Rotationen.
        if ($cursorImported && isset($byOrder[(int) $bs['topic_cursor']]) && $settings->get(TopicService::CURSOR_ID_KEY) === '') {
            $settings->set(TopicService::CURSOR_ID_KEY, (string) $byOrder[(int) $bs['topic_cursor']]);
        }
        return $r;
    }

    /**
     * Ein früherer Import hat Bilder, die er nicht übernehmen konnte, als Verweis auf die alte Adresse gelassen.
     * Bei einem erneuten Import werden solche Verweise ersetzt, sobald das Bild im Export steht und gespeichert werden kann.
     *
     * @param array<string, mixed> $post @param array<string, string> $bundleImages @param array<string, string> $map @param array<string, mixed> $r
     */
    private function repairImages(PostRepository $posts, array $post, int $siteId, string $origin, array $bundleImages, array &$map, array &$r): void
    {
        $prefix = $origin . '/assets/';
        $cover = (string) $post['cover_image'];
        $body = (string) $post['body_html'];
        if (!str_starts_with($cover, $prefix) && !str_contains($body, $prefix)) {
            return;
        }
        $warnings = [];
        $scratch = ['warnings' => &$warnings, 'images' => 0];
        $newCover = str_starts_with($cover, $prefix) && isset($bundleImages[substr($cover, strlen($origin) + 1)])
            ? $this->resolveImage($siteId, $cover, $origin, $bundleImages, $map, $scratch) : $cover;
        $newBody = (string) preg_replace_callback('#(<img\b[^>]*?\bsrc=)(["\'])(' . preg_quote($prefix, '#') . '[^"\']+)\2#i', function (array $m) use ($siteId, $origin, $bundleImages, &$map, &$scratch): string {
            $path = substr($m[3], strlen($origin) + 1);
            return $m[1] . $m[2] . (isset($bundleImages[$path]) ? $this->resolveImage($siteId, $m[3], $origin, $bundleImages, $map, $scratch) : $m[3]) . $m[2];
        }, $body);
        if ($newCover !== $cover || $newBody !== $body) {
            $changed = [];
            if ($newCover !== $cover && !str_starts_with($newCover, $prefix)) {
                $changed['cover_image'] = $newCover;
            }
            if ($newBody !== $body) {
                $changed['body_html'] = $newBody;
            }
            if ($changed !== []) {
                $posts->update((int) $post['id'], $changed);
                $r['images_repaired']++;
            }
        }
        $r['images'] += $scratch['images'];
        foreach ($warnings as $w) {
            $r['warnings'][] = $w;
        }
    }

    /** @param array<string, string> $bundleImages @param array<string, string> $map @param array<string, mixed> $r */
    private function resolveImage(int $siteId, string $ref, string $origin, array $bundleImages, array &$map, array &$r): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return '';
        }
        $path = $this->localPath($ref, $origin);
        if ($path === null) {
            return Html::safeUrl($ref, true) ? $ref : '';       // fremde absolute Adresse: unverändert, sonst verwerfen
        }
        if (isset($map[$path])) {
            return $map[$path];
        }
        if (isset($bundleImages[$path])) {
            try {
                $raw = base64_decode($bundleImages[$path], true);
                if ($raw === false) {
                    throw new InvalidArgumentException('base64');
                }
                $map[$path] = $this->images->save($siteId, $raw);
                $r['images']++;
                return $map[$path];
            } catch (Throwable) {
                $r['warnings'][] = "Bild nicht übernommen, Verweis auf die alte Adresse: $path";
            }
        } else {
            $r['warnings'][] = "Bild fehlt im Export, Verweis auf die alte Adresse: $path";
        }
        return $map[$path] = $origin . '/' . $path;
    }

    /** @param array<string, string> $bundleImages @param array<string, string> $map @param array<string, mixed> $r */
    private function rewriteBody(int $siteId, string $html, string $origin, array $bundleImages, array &$map, array &$r): string
    {
        $html = (string) preg_replace_callback('#(<img\b[^>]*?\bsrc=)(["\'])([^"\']+)\2#i', function (array $m) use ($siteId, $origin, $bundleImages, &$map, &$r): string {
            return $m[1] . $m[2] . $this->resolveImage($siteId, $m[3], $origin, $bundleImages, $map, $r) . $m[2];
        }, $html);
        // Relative Links zeigen sonst auf die neue Domain statt auf Coastal.
        return (string) preg_replace_callback('#(<a\b[^>]*?\bhref=)(["\'])(/[^"\'/][^"\']*|(?!https?:|mailto:|tel:|\#|/)[^"\']+)\2#i', static function (array $m) use ($origin): string {
            return $m[1] . $m[2] . $origin . '/' . ltrim($m[3], '/') . $m[2];
        }, $html);
    }

    /** Pfad unterhalb von assets/ (relativ, mit führendem Slash oder mit der alten Herkunft), sonst null. */
    private function localPath(string $ref, string $origin): ?string
    {
        if (str_starts_with($ref, $origin . '/')) {
            $ref = substr($ref, strlen($origin));
        }
        if (preg_match('#^(?:https?:)?//#i', $ref) === 1) {
            return null;
        }
        $path = ltrim($ref, '/');
        return str_starts_with($path, 'assets/') && !str_contains($path, '..') ? $path : null;
    }

    private function date(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $v) === 1 ? str_replace('T', ' ', substr($v, 0, 19)) : null;
    }
}
