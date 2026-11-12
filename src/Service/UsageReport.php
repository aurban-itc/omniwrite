<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Monatliche Nutzungsübersicht pro Account und Website für die Abrechnung (Superadmin).
 * Der Monat richtet sich wie bei den Limits nach der Zeitzone der jeweiligen Website.
 */
final class UsageReport
{
    public const KINDS = ['ai_post', 'ai_text', 'ai_image'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Gültiger Monat als "JJJJ-MM"; alles andere ergibt den aktuellen Monat (UTC). */
    public static function normalizeMonth(?string $month, ?int $now = null): string
    {
        if ($month !== null && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) === 1 && (int) $m[1] >= 2020 && (int) $m[1] <= 2100) {
            return $month;
        }
        return gmdate('Y-m', $now ?? time());
    }

    /**
     * @return list<array{
     *   account_id: int, account_name: string, plan: string, posts_limit: ?int,
     *   sites: list<array{id: int, slug: string, name: string, ai_post: int, ai_text: int, ai_image: int}>,
     *   ai_post: int, ai_text: int, ai_image: int
     * }>
     */
    public function forMonth(string $month): array
    {
        $rows = $this->pdo->query(
            'SELECT s.id, s.slug, s.name, s.timezone, a.id AS account_id, a.name AS account_name, p.name AS plan_name, p.posts_per_month
             FROM sites s JOIN accounts a ON a.id = s.account_id LEFT JOIN plans p ON p.id = a.plan_id
             ORDER BY a.id, s.id'
        )->fetchAll();
        $stmt = $this->pdo->prepare('SELECT kind, COALESCE(SUM(quantity), 0) AS n FROM usage_events WHERE site_id = ? AND created_at >= ? AND created_at < ? GROUP BY kind');
        $accounts = [];
        foreach ($rows as $r) {
            [$from, $to] = $this->range($month, (string) $r['timezone']);
            $stmt->execute([(int) $r['id'], $from, $to]);
            $sums = array_fill_keys(self::KINDS, 0);
            foreach ($stmt->fetchAll() as $u) {
                if (isset($sums[$u['kind']])) {
                    $sums[$u['kind']] = (int) $u['n'];
                }
            }
            $aid = (int) $r['account_id'];
            $accounts[$aid] ??= [
                'account_id' => $aid, 'account_name' => (string) $r['account_name'], 'plan' => (string) ($r['plan_name'] ?? ''),
                'posts_limit' => $r['posts_per_month'] === null ? null : (int) $r['posts_per_month'],
                'sites' => [], 'ai_post' => 0, 'ai_text' => 0, 'ai_image' => 0,
            ];
            $accounts[$aid]['sites'][] = ['id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'name' => (string) $r['name']] + $sums;
            foreach (self::KINDS as $k) {
                $accounts[$aid][$k] += $sums[$k];
            }
        }
        return array_values($accounts);
    }

    /** @param list<array<string, mixed>> $report */
    public static function csv(string $month, array $report): string
    {
        $out = [['Monat', 'Account', 'Plan', 'Website', 'KI-Beiträge', 'KI-Texte', 'KI-Bilder']];
        foreach ($report as $a) {
            foreach ($a['sites'] as $s) {
                $out[] = [$month, $a['account_name'], $a['plan'], $s['slug'], $s['ai_post'], $s['ai_text'], $s['ai_image']];
            }
        }
        $lines = [];
        foreach ($out as $row) {
            $lines[] = implode(';', array_map(self::cell(...), $row));
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";   // BOM + Semikolon, damit Excel (de) die Datei direkt richtig öffnet
    }

    private static function cell(int|string $v): string
    {
        $s = (string) $v;
        if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_int($v)) {
            $s = "'" . $s;   // Formel-Einschleusung in Tabellenprogrammen verhindern
        }
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /** @return array{0: string, 1: string} [von, bis) als UTC-Zeitstempel */
    private function range(string $month, string $timezone): array
    {
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }
        $start = new DateTimeImmutable($month . '-01 00:00:00', $tz);
        $utc = new DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $start->modify('first day of next month')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
}
