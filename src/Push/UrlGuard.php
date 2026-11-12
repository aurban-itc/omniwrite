<?php
declare(strict_types=1);

namespace OmniWrite\Push;

use OmniWrite\Net\DnsResolver;

/** Schützt vor Zielen im internen Netz (SSRF): nur HTTPS, nur öffentliche IPv4-Adressen, die geprüfte Adresse wird festgehalten. */
final class UrlGuard
{
    public function __construct(private readonly DnsResolver $dns)
    {
    }

    /** @return null|string Fehlerschlüssel der Übersetzung, null = Adresse formal in Ordnung */
    public function validateFormat(string $url): ?string
    {
        $p = parse_url($url);
        if ($url === '' || mb_strlen($url) > 300 || !is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host'])
            || isset($p['user']) || isset($p['pass']) || isset($p['port']) && (int) $p['port'] !== 443 || preg_match('/[\x00-\x20]/', $url) === 1) {
            return 'err.push_url_invalid';
        }
        $host = strtolower((string) $p['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_contains($host, ':') || !str_contains($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || $host === 'localhost') {
            return 'err.push_url_host';
        }
        return null;
    }

    /** @return array{0: ?string, 1: ?string} [IP-Adresse zum Festhalten, Fehlerschlüssel] */
    public function resolve(string $url): array
    {
        if (($e = $this->validateFormat($url)) !== null) {
            return [null, $e];
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $ips = $this->dns->addresses($host);
        if ($ips === []) {
            return [null, 'err.push_url_dns'];
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return [null, 'err.push_url_private'];
            }
        }
        return [$ips[0], null];
    }
}
