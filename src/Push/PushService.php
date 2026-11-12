<?php
declare(strict_types=1);

namespace OmniWrite\Push;

use DateInterval;
use DateTimeImmutable;
use OmniWrite\Auth\AuditLogger;
use OmniWrite\Auth\SecretBox;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\SiteContext;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\PushStateRepository;
use OmniWrite\Repository\PushTargetRepository;
use OmniWrite\Service\BlogView;
use OmniWrite\Service\PublicBlogService;
use OmniWrite\Service\ResolvedSite;
use OmniWrite\Support\Html;
use PDO;
use Throwable;

/**
 * Hält veröffentlichte Beiträge mit externen Zielen synchron (Webhook, GitHub). Es gibt keine Ereignisliste:
 * Ein Abgleich vergleicht den Inhalts-Hash jedes veröffentlichten Beitrags mit dem zuletzt zugestellten Stand
 * und stellt Neues, Geändertes und Zurückgezogenes zu. Das fängt jeden Weg zum Veröffentlichen ab (Editor, Review-Mail, Import)
 * und wiederholt Fehlschläge mit wachsendem Abstand (5 Min, 10, 20 … höchstens 6 Std, nach 8 Versuchen Schluss bis zur Änderung oder manuell).
 */
final class PushService
{
    public const KINDS = ['webhook', 'github'];
    private const MAX_ATTEMPTS = 8;

    /** @param array<string, PushConnector> $connectors kind => Connector */
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly SecretBox $secrets,
        private readonly PublicBlogService $blog,
        private readonly array $connectors,
        private readonly UrlGuard $guard,
    ) {
    }

    /** @return list<array<string, mixed>> Ziele mit Anzeigetext (ohne Geheimnisse) und Zustellstand */
    public function list(ResolvedSite $s): array
    {
        $states = new PushStateRepository($this->pdo);
        $out = [];
        foreach ($this->targets($s)->listAll() as $t) {
            $out[] = [
                'id' => (int) $t['id'], 'kind' => (string) $t['kind'], 'name' => (string) $t['name'], 'active' => (int) $t['active'] === 1,
                'summary' => $this->summary($t), 'counts' => $states->counts($s->id(), (int) $t['id']), 'error' => $states->lastError($s->id(), (int) $t['id']),
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $in */
    public function add(ResolvedSite $s, User $actor, string $kind, array $in, ?string $ip = null): int
    {
        $this->requireOwner($s);
        $cfg = match ($kind) {
            'webhook' => $this->webhookConfig($in),
            'github' => $this->githubConfig($in),
            default => throw new ValidationException(['kind' => 'err.push_kind']),
        };
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        if ($name === '') {
            $name = $kind === 'webhook' ? (string) parse_url((string) $cfg['url'], PHP_URL_HOST) : (string) $cfg['repo'];
        }
        $id = $this->targets($s)->insert([
            'kind' => $kind, 'name' => $name, 'active' => 1,
            'config_enc' => $this->secrets->encrypt(json_encode($cfg, JSON_THROW_ON_ERROR)),
        ]);
        $this->audit->log('push.add', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'target_id' => $id, 'kind' => $kind], $ip);
        return $id;
    }

    public function toggle(ResolvedSite $s, User $actor, int $id, ?string $ip = null): void
    {
        $this->requireOwner($s);
        $row = $this->targets($s)->find($id) ?? throw new NotFoundException('Ziel nicht gefunden');
        $this->targets($s)->update($id, ['active' => (int) $row['active'] === 1 ? 0 : 1]);
        $this->audit->log('push.toggle', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'target_id' => $id], $ip);
    }

    public function remove(ResolvedSite $s, User $actor, int $id, ?string $ip = null): void
    {
        $this->requireOwner($s);
        $this->targets($s)->find($id) ?? throw new NotFoundException('Ziel nicht gefunden');
        $this->pdo->prepare('DELETE FROM push_state WHERE site_id = :s AND target_id = :t')->execute(['s' => $s->id(), 't' => $id]);
        $this->targets($s)->delete($id);
        $this->audit->log('push.remove', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'target_id' => $id], $ip);
    }

    /** Sofort abgleichen (auch bereits endgültig fehlgeschlagene Zustellungen erneut versuchen). @return list<string> Zeilen für die Anzeige */
    public function sendNow(ResolvedSite $s, User $actor, int $id, ?string $ip = null): array
    {
        $this->requireOwner($s);
        $this->targets($s)->find($id) ?? throw new NotFoundException('Ziel nicht gefunden');
        (new PushStateRepository($this->pdo))->retryFailed($s->id(), $id);
        $this->audit->log('push.send', $actor->id, $s->accountId(), ['site_id' => $s->id(), 'target_id' => $id], $ip);
        return $this->syncSite($s->site, new DateTimeImmutable('now', new \DateTimeZone('UTC')), $id);
    }

    /** Cron: alle Sites mit aktiven Zielen. @return list<string> */
    public function syncAll(DateTimeImmutable $now): array
    {
        $lines = [];
        $ids = $this->pdo->query('SELECT DISTINCT site_id FROM push_targets WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $siteId) {
            $site = $this->pdo->prepare('SELECT * FROM sites WHERE id = :id');
            $site->execute(['id' => (int) $siteId]);
            if (($row = $site->fetch()) === false) {
                continue;
            }
            try {
                array_push($lines, ...$this->syncSite($row, $now));
            } catch (Throwable $e) {
                error_log((string) $e);
                $lines[] = $row['slug'] . ' failed: ' . $e->getMessage();
            }
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $site @return list<string>
     */
    public function syncSite(array $site, DateTimeImmutable $now, ?int $onlyTarget = null, int $limit = 25): array
    {
        $ctx = new SiteContext((int) $site['id']);
        $targets = array_filter((new PushTargetRepository($this->pdo, $ctx))->listAll(true), fn (array $t): bool => $onlyTarget === null || (int) $t['id'] === $onlyTarget);
        if ($targets === []) {
            return [];
        }
        $posts = new PostRepository($this->pdo, $ctx);
        $view = $this->blog->view($site, false);
        $desired = [];
        $after = 0;
        while (($page = $posts->listPublishedFull($after)) !== []) {
            foreach ($page as $p) {
                $payload = $this->payload($p, $view);
                $desired[(int) $p['id']] = [$payload, hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
                $after = (int) $p['id'];
            }
        }
        $states = new PushStateRepository($this->pdo);
        $lines = [];
        $targetRepo = new PushTargetRepository($this->pdo, $ctx);
        foreach ($targets as $t) {
            $tid = (int) $t['id'];
            $token = $targetRepo->claim($tid, $now);
            if ($token === null) {
                $lines[] = $site['slug'] . ' #' . $tid . ': Abgleich läuft bereits';
                continue;
            }
            try {
                array_push($lines, ...$this->syncTarget($site, $t, $desired, $posts, $states, $now, $limit, $targetRepo, $token));
            } finally {
                $targetRepo->release($tid, $token);
            }
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $site @param array<string, mixed> $t @param array<int, array{0: array<string, mixed>, 1: string}> $desired
     * @return list<string>
     */
    private function syncTarget(array $site, array $t, array $desired, PostRepository $posts, PushStateRepository $states, DateTimeImmutable $now, int $limit, PushTargetRepository $targetRepo, string $token): array
    {
        $lines = [];
        $tid = (int) $t['id'];
        $connector = $this->connectors[(string) $t['kind']] ?? null;
        try {
            $cfg = json_decode($this->secrets->decrypt((string) $t['config_enc']), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [$site['slug'] . ' #' . $tid . ' failed: Zugangsdaten nicht lesbar (' . $e->getMessage() . ')'];
        }
        if ($connector === null || !is_array($cfg)) {
            return [];
        }
        $state = $states->forTarget((int) $site['id'], $tid);
        $todo = [];
        foreach ($desired as $pid => [$payload, $hash]) {
            $row = $state[$pid] ?? null;
            if ($row !== null && $row['delivered_hash'] === $hash) {
                continue;
            }
            if ($row !== null && $row['attempt_hash'] === $hash && !$this->due($row, $now)) {
                continue;
            }
            $todo[] = [$pid, $payload, $hash, $row];
        }
        foreach ($state as $pid => $row) {
            if ($row['delivered_hash'] === null || isset($desired[$pid])) {
                continue;
            }
            if ($row['attempt_hash'] === '-' && !$this->due($row, $now)) {
                continue;
            }
            $post = $posts->find($pid);
            $todo[] = [$pid, ['id' => $pid, 'slug' => (string) ($post['slug'] ?? $row['post_slug'] ?? ''), 'title' => (string) ($post['title'] ?? $row['post_title'] ?? '')], null, $row];
        }
        foreach (array_slice($todo, 0, $limit) as [$pid, $payload, $hash, $row]) {
            if (!$targetRepo->renew($tid, $token, new DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
                $lines[] = $site['slug'] . ' #' . $tid . ': Sperre verloren, Abgleich abgebrochen';
                break;
            }
            $lines[] = $this->deliver($site, $tid, $connector, $cfg, $pid, $payload, $hash, $row, $now, $states);
        }
        if (count($todo) > $limit) {
            $lines[] = $site['slug'] . ' #' . $tid . ': ' . (count($todo) - $limit) . ' weitere beim nächsten Lauf';
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $site @param array<string, mixed> $cfg @param array<string, mixed> $payload @param array<string, mixed>|null $row
     */
    private function deliver(array $site, int $tid, PushConnector $connector, array $cfg, int $pid, array $payload, ?string $hash, ?array $row, DateTimeImmutable $now, PushStateRepository $states): string
    {
        $sid = (int) $site['id'];
        $prev = (string) ($row['remote_ref'] ?? '');
        $stamp = $now->format('Y-m-d H:i:s');
        $label = $site['slug'] . ' #' . $tid . ' ' . ($payload['slug'] ?? $pid);
        try {
            if ($hash === null) {
                $connector->remove($cfg, $site, $payload, $prev);
                $states->save($sid, $tid, $pid, ['delivered_hash' => null, 'attempt_hash' => '-', 'status' => 'removed', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => '', 'remote_ref' => '', 'delivered_at' => $stamp]);
                return "$label entfernt";
            }
            $ref = $connector->publish($cfg, $site, $payload, $prev);
            $states->save($sid, $tid, $pid, ['delivered_hash' => $hash, 'attempt_hash' => $hash, 'status' => 'ok', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => '', 'remote_ref' => mb_substr($ref, 0, 255), 'post_slug' => mb_substr((string) ($payload['slug'] ?? ''), 0, 255), 'post_title' => mb_substr((string) ($payload['title'] ?? ''), 0, 255), 'delivered_at' => $stamp]);
            return "$label zugestellt";
        } catch (PushException $e) {
            $retryable = $e->retryable;
            $message = $e->getMessage();
        } catch (Throwable $e) {
            error_log((string) $e);
            $retryable = true;
            $message = 'Unerwarteter Fehler';
        }
        $attempts = (int) ($row['attempts'] ?? 0);
        $same = $row !== null && $row['attempt_hash'] === ($hash ?? '-');
        $attempts = $same ? $attempts + 1 : 1;
        $next = null;
        if ($retryable && $attempts < self::MAX_ATTEMPTS) {
            $next = $now->add(new DateInterval('PT' . min(21600, 300 * (2 ** ($attempts - 1))) . 'S'))->format('Y-m-d H:i:s');
        }
        $states->save($sid, $tid, $pid, [
            'attempt_hash' => $hash ?? '-', 'status' => 'failed', 'attempts' => $attempts, 'next_attempt_at' => $next,
            'last_error' => mb_substr($message, 0, 600),
        ]);
        return "$label fehlgeschlagen: $message";
    }

    /** @param array<string, mixed> $row */
    private function due(array $row, DateTimeImmutable $now): bool
    {
        if ($row['next_attempt_at'] === null || $row['next_attempt_at'] === '') {
            return false;
        }
        $t = strtotime((string) $row['next_attempt_at'] . ' UTC');
        return $t !== false && $t <= $now->getTimestamp();
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function payload(array $p, BlogView $b): array
    {
        $slug = (string) $p['slug'];
        $cover = (string) $p['cover_image'];
        $iso = static fn (mixed $ts): ?string => $ts ? gmdate('c', (int) strtotime((string) $ts . ' UTC')) : null;
        return [
            'id' => (int) $p['id'], 'slug' => $slug, 'title' => (string) $p['title'], 'excerpt' => (string) $p['excerpt'],
            'category' => (string) $p['category'], 'keywords' => (string) $p['keywords'], 'meta_description' => (string) $p['meta_description'],
            'url' => $b->abs('/' . rawurlencode($slug)),
            'cover_image' => $cover !== '' ? $b->absMedia($cover) : null, 'cover_alt' => (string) $p['cover_alt'],
            'read_minutes' => (int) $p['read_minutes'],
            'published_at' => $iso($p['published_at'] ?? $p['created_at'] ?? null), 'updated_at' => $iso($p['updated_at'] ?? $p['published_at'] ?? null),
            'body_html' => Html::absolutize((string) $p['body_html'], $b->origin, $b->abs('/' . rawurlencode($slug)), $b->abs('/')),
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function webhookConfig(array $in): array
    {
        $url = trim((string) ($in['url'] ?? ''));
        $secret = (string) ($in['secret'] ?? '');
        $errors = [];
        [$ip, $err] = $this->guard->resolve($url);
        if ($ip === null) {
            $errors['url'] = (string) $err;
        }
        if (strlen($secret) < 16 || strlen($secret) > 200 || preg_match('/[\x00-\x1f]/', $secret) === 1) {
            $errors['secret'] = 'err.push_secret';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['url' => $url, 'secret' => $secret];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function githubConfig(array $in): array
    {
        $repo = trim((string) ($in['repo'] ?? ''));
        $branch = trim((string) ($in['branch'] ?? '')) ?: 'main';
        $dir = trim(trim((string) ($in['dir'] ?? 'content/posts')), '/');
        $ext = (string) ($in['ext'] ?? 'md') === 'html' ? 'html' : 'md';
        $token = trim((string) ($in['token'] ?? ''));
        $errors = [];
        if (preg_match('#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $repo) !== 1) {
            $errors['repo'] = 'err.push_repo';
        }
        if (preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $branch) !== 1 || str_contains($branch, '..') || str_starts_with($branch, '/')) {
            $errors['branch'] = 'err.push_branch';
        }
        if (preg_match('#^[A-Za-z0-9_./-]{0,150}$#', $dir) !== 1 || str_contains($dir, '..') || str_contains($dir, '//')) {
            $errors['dir'] = 'err.push_dir';
        }
        if (preg_match('/^[A-Za-z0-9_-]{20,255}$/', $token) !== 1) {
            $errors['token'] = 'err.push_token';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['repo' => $repo, 'branch' => $branch, 'dir' => $dir, 'ext' => $ext, 'token' => $token];
    }

    /** @param array<string, mixed> $t */
    private function summary(array $t): string
    {
        try {
            $cfg = json_decode($this->secrets->decrypt((string) $t['config_enc']), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '?';
        }
        if (!is_array($cfg)) {
            return '?';
        }
        return (string) $t['kind'] === 'webhook'
            ? (string) parse_url((string) ($cfg['url'] ?? ''), PHP_URL_HOST)
            : (string) ($cfg['repo'] ?? '') . '@' . (string) ($cfg['branch'] ?? '') . ':' . (string) ($cfg['dir'] ?? '') . '/*.' . (string) ($cfg['ext'] ?? 'md');
    }

    private function requireOwner(ResolvedSite $s): void
    {
        if (!$s->canPublish()) {
            throw new AccessDeniedException('Nur Owner dürfen Anbindungen pflegen');
        }
    }

    private function targets(ResolvedSite $s): PushTargetRepository
    {
        return new PushTargetRepository($this->pdo, $s->context);
    }
}
