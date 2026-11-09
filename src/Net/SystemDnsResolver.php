<?php
declare(strict_types=1);

namespace OmniWrite\Net;

final class SystemDnsResolver implements DnsResolver
{
    public function cname(string $host): array
    {
        $out = [];
        foreach (@dns_get_record($host, DNS_CNAME) ?: [] as $r) {
            if (isset($r['target'])) {
                $out[] = rtrim(strtolower((string) $r['target']), '.');
            }
        }
        return $out;
    }

    public function txt(string $host): array
    {
        $out = [];
        foreach (@dns_get_record($host, DNS_TXT) ?: [] as $r) {
            if (isset($r['txt'])) {
                $out[] = trim((string) $r['txt']);
            }
        }
        return $out;
    }

    public function addresses(string $host): array
    {
        $out = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
            if (isset($r['ip'])) {
                $out[] = (string) $r['ip'];
            }
        }
        return $out;
    }
}
