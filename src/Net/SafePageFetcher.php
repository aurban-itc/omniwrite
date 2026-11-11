<?php
declare(strict_types=1);

namespace OmniWrite\Net;

/**
 * Ruft öffentliche Webseiten ab, ohne dass Nutzer damit interne Ziele erreichen (SSRF):
 *  - nur http/https auf den Ports 80/443, keine Zugangsdaten in der Adresse
 *  - jeder Host (auch nach jeder Weiterleitung) wird aufgelöst; zeigt irgendeine Adresse auf ein nicht öffentliches Netz, wird abgelehnt
 *  - die Verbindung geht dann nur an die geprüfte IP (Transport pinnt sie), Weiterleitungen folgt dieser Code selbst
 *  - Antwortgröße und Laufzeit sind begrenzt, nur HTML wird akzeptiert
 */
final class SafePageFetcher implements PageFetcher
{
    private const MAX_REDIRECTS = 4;

    /** @var callable(string): list<string> */
    private $resolver;

    /** @param ?callable(string): list<string> $resolver Host => IP-Adressen (Standard: DNS A und AAAA) */
    public function __construct(
        private readonly PageTransport $transport = new CurlPageTransport(),
        ?callable $resolver = null,
        private readonly int $maxBytes = 1_500_000,
        private readonly int $timeoutSeconds = 12,
    ) {
        $this->resolver = $resolver ?? self::systemResolve(...);
    }

    public function fetch(string $url): array
    {
        $current = self::normalize($url);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($current);
            $host = (string) $parts['host'];
            $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
            $ip = $this->pinnedIp($host);
            $res = $this->transport->get($current, $host, $port, $ip, $this->maxBytes, $this->timeoutSeconds);

            if ($res['status'] >= 300 && $res['status'] < 400 && $res['location'] !== '') {
                $current = self::normalize(self::resolveLocation($current, $res['location']));
                continue;
            }
            if ($res['status'] === 0) {
                throw new FetchException('err.fetch_failed', 'no response from ' . $host);
            }
            if ($res['status'] < 200 || $res['status'] >= 300) {
                throw new FetchException('err.fetch_failed', 'HTTP ' . $res['status'] . ' from ' . $host);
            }
            if ($res['contentType'] !== '' && !str_contains($res['contentType'], 'html')) {
                throw new FetchException('err.fetch_not_html');
            }
            if (trim($res['body']) === '') {
                throw new FetchException('err.fetch_failed', 'empty body from ' . $host);
            }
            return ['url' => $current, 'body' => $res['body']];
        }
        throw new FetchException('err.fetch_failed', 'too many redirects');
    }

    /** @throws FetchException */
    private static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new FetchException('err.fetch_url_invalid');
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1 && !preg_match('#^[^/]+:\d+(/|$)#', $url)) {
                throw new FetchException('err.fetch_url_invalid'); // javascript:, file:, mailto: ...
            }
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new FetchException('err.fetch_url_invalid');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchException('err.fetch_url_invalid');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new FetchException('err.fetch_blocked', 'port ' . $port);
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (!str_starts_with($host, '[') && preg_match('/[^\x20-\x7e]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $host = (string) (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host);
        }
        if ($host === '' || preg_match('/^[a-z0-9._\-\[\]:]+$/', $host) !== 1) {
            throw new FetchException('err.fetch_url_invalid');
        }
        $hostPart = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $scheme . '://' . $hostPart . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** @throws FetchException */
    private function pinnedIp(string $host): string
    {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            $ips = [$bare];
        } else {
            if ($bare === 'localhost' || !str_contains($bare, '.') || str_ends_with($bare, '.local') || str_ends_with($bare, '.internal') || str_ends_with($bare, '.localhost')) {
                throw new FetchException('err.fetch_blocked', 'host ' . $bare);
            }
            $ips = ($this->resolver)($bare);
        }
        if ($ips === []) {
            throw new FetchException('err.fetch_failed', 'no DNS result for ' . $bare);
        }
        foreach ($ips as $ip) {
            if (!IpGuard::isPublic($ip)) {
                throw new FetchException('err.fetch_blocked', $bare . ' -> ' . $ip);
            }
        }
        // IPv4 bevorzugen: viele Hosting-Umgebungen haben kein ausgehendes IPv6.
        usort($ips, static fn (string $a, string $b): int => str_contains($a, ':') <=> str_contains($b, ':'));
        return $ips[0];
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return $b['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        return $origin . $dir . $location;
    }

    /** @return list<string> */
    private static function systemResolve(string $host): array
    {
        $out = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            if (isset($r['ip'])) {
                $out[] = (string) $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $out[] = (string) $r['ipv6'];
            }
        }
        if ($out === []) {
            $out = @gethostbynamel($host) ?: [];
        }
        return array_values(array_unique($out));
    }
}
