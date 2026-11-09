<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use DateTimeImmutable;
use DateTimeZone;
use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\SiteContext;
use OmniWrite\Domain\User;
use OmniWrite\Repository\SiteSettingsRepository;
use PDO;

/** Zeitplan pro Site: Wochentag und Stunde in der Zeitzone der Site. Veröffentlicht wird nie automatisch. */
final class ScheduleService
{
    public function __construct(private readonly PDO $pdo, private readonly AuditLogger $audit)
    {
    }

    /** @return array{enabled: bool, weekday: int, hour: int} weekday 1 = Montag … 7 = Sonntag */
    public function get(ResolvedSite $s): array
    {
        return $this->read(new SiteSettingsRepository($this->pdo, $s->context));
    }

    /** @param array<string, mixed> $in enabled, weekday, hour */
    public function save(ResolvedSite $s, User $actor, array $in, ?string $ip = null): void
    {
        if (!$s->canPublish()) {
            throw new AccessDeniedException('Nur Owner dürfen den Zeitplan ändern');
        }
        $repo = new SiteSettingsRepository($this->pdo, $s->context);
        $repo->set('schedule_enabled', !empty($in['enabled']) ? '1' : '0');
        $repo->set('schedule_weekday', (string) min(7, max(1, (int) ($in['weekday'] ?? 1))));
        $repo->set('schedule_hour', (string) min(23, max(0, (int) ($in['hour'] ?? 6))));
        $this->audit->log('schedule.save', $actor->id, $s->accountId(), ['site_id' => $s->id()], $ip);
    }

    /** Ist die Site jetzt (volle Stunde in ihrer Zeitzone) fällig und diese Woche noch nicht gelaufen? @param array<string, mixed> $site */
    public function isDue(array $site, DateTimeImmutable $nowUtc): bool
    {
        $repo = new SiteSettingsRepository($this->pdo, new SiteContext((int) $site['id']));
        $cfg = $this->read($repo);
        if (!$cfg['enabled']) {
            return false;
        }
        $local = $this->local($site, $nowUtc);
        // Gibt es die gewählte Stunde an diesem Tag nicht (Umstellung auf Sommerzeit), gilt die erste vorhandene danach.
        $hour = (int) $local->setTime($cfg['hour'], 0)->format('G');
        return (int) $local->format('N') === $cfg['weekday']
            && (int) $local->format('G') === $hour
            && $repo->get('last_run_week') !== $local->format('o-W');
    }

    /** Reserviert die laufende Woche; true für genau einen Aufrufer. @param array<string, mixed> $site */
    public function claimWeek(array $site, DateTimeImmutable $nowUtc): bool
    {
        return (new SiteSettingsRepository($this->pdo, new SiteContext((int) $site['id'])))
            ->claim('last_run_week', $this->local($site, $nowUtc)->format('o-W'));
    }

    /** @return list<array<string, mixed>> Sites mit eingeschaltetem Zeitplan */
    public function scheduledSites(): array
    {
        return $this->pdo->query(
            "SELECT s.* FROM sites s JOIN site_settings ss ON ss.site_id = s.id AND ss.k = 'schedule_enabled' AND ss.v = '1' ORDER BY s.id"
        )->fetchAll();
    }

    /** @return array{enabled: bool, weekday: int, hour: int} */
    private function read(SiteSettingsRepository $repo): array
    {
        return [
            'enabled' => $repo->get('schedule_enabled', '0') === '1',
            'weekday' => min(7, max(1, (int) $repo->get('schedule_weekday', '1'))),
            'hour' => min(23, max(0, (int) $repo->get('schedule_hour', '6'))),
        ];
    }

    /** @param array<string, mixed> $site */
    private function local(array $site, DateTimeImmutable $nowUtc): DateTimeImmutable
    {
        try {
            $tz = new DateTimeZone((string) $site['timezone']);
        } catch (\Exception) {
            $tz = new DateTimeZone('UTC');
        }
        return $nowUtc->setTimezone($tz);
    }
}
