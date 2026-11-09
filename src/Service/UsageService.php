<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\UsageRepository;
use PDO;

/**
 * Zählt KI-Nutzung pro Site und erzwingt Limits.
 *  - ai_post:  erzeugte Beiträge, Monatslimit aus dem Plan (plans.posts_per_month, NULL = unbegrenzt)
 *  - ai_text:  Überarbeitung, SEO-Felder, Themenvorschläge
 *  - ai_image: Bilder
 * Für alle gilt ein Stundenlimit pro Site als Schutz vor Fehlbedienung und Kostenausreißern.
 */
final class UsageService
{
    public const HOURLY_LIMITS = ['ai_post' => 20, 'ai_text' => 60, 'ai_image' => 15];

    public function __construct(private readonly PDO $pdo, private readonly AccountRepository $accounts)
    {
    }

    /**
     * Bucht den Aufruf vorab und prüft danach die Limits (erst buchen, dann zählen: parallele Anfragen können das Limit nicht gemeinsam überschreiten).
     *
     * @param array<string, mixed> $meta
     * @return int Ereignis-ID, für release() falls der Aufruf scheitert
     * @throws UsageLimitException
     */
    public function reserve(ResolvedSite $s, string $kind, array $meta = [], ?int $now = null): int
    {
        if (!isset(self::HOURLY_LIMITS[$kind])) {
            throw new InvalidArgumentException("Unbekannte Nutzungsart: $kind");
        }
        $now ??= time();
        $repo = new UsageRepository($this->pdo, $s->context);
        $id = $repo->insert($kind, $meta, gmdate('Y-m-d H:i:s', $now));
        if ($repo->sumSince($kind, gmdate('Y-m-d H:i:s', $now - 3600)) > self::HOURLY_LIMITS[$kind]) {
            $repo->delete($id);
            throw new UsageLimitException('err.limit_hourly');
        }
        if ($kind === 'ai_post') {
            $limit = $this->accounts->postsPerMonth($s->accountId());
            if ($limit !== null && $repo->sumSince($kind, $this->monthStart($s, $now)) > $limit) {
                $repo->delete($id);
                throw new UsageLimitException('err.limit_monthly');
            }
        }
        return $id;
    }

    /** Nimmt eine Buchung zurück, weil der Aufruf scheiterte (der Kunde zahlt nicht für Fehler). */
    public function release(ResolvedSite $s, int $eventId): void
    {
        (new UsageRepository($this->pdo, $s->context))->delete($eventId);
    }

    /** @return array{posts_used: int, posts_limit: ?int, texts_used: int, images_used: int} Stand im laufenden Monat der Site */
    public function summary(ResolvedSite $s, ?int $now = null): array
    {
        $now ??= time();
        $repo = new UsageRepository($this->pdo, $s->context);
        $since = $this->monthStart($s, $now);
        return [
            'posts_used' => $repo->sumSince('ai_post', $since),
            'posts_limit' => $this->accounts->postsPerMonth($s->accountId()),
            'texts_used' => $repo->sumSince('ai_text', $since),
            'images_used' => $repo->sumSince('ai_image', $since),
        ];
    }

    /** Monatsanfang in der Zeitzone der Site, als UTC-Zeitstempel. */
    private function monthStart(ResolvedSite $s, int $now): string
    {
        $tz = new DateTimeZone((string) $s->site['timezone']);
        $start = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->modify('first day of this month')->setTime(0, 0);
        return $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
