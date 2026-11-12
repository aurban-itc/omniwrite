<?php
declare(strict_types=1);

namespace OmniWrite\Net;

/** Entscheidet, ob eine IP-Adresse im öffentlichen Internet liegt (Schutz vor Abrufen interner Ziele, SSRF). */
final class IpGuard
{
    private const V4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** Nur 2000::/3 ist globale Unicast-Adresse; daraus sind Dokumentation, Tunnel- und Übergangsbereiche ausgenommen. */
    private const V6_BLOCKED = ['2001::/32', '2001:2::/48', '2001:10::/28', '2001:db8::/32', '2002::/16', '3fff::/20'];

    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return !self::inAny($packed, self::V4_BLOCKED);
        }
        // IPv4-mapped (::ffff:a.b.c.d): wie die enthaltene IPv4-Adresse behandeln.
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return !self::inAny(substr($packed, 12), self::V4_BLOCKED);
        }
        if ((ord($packed[0]) & 0xe0) !== 0x20) { // nicht 2000::/3
            return false;
        }
        return !self::inAny($packed, self::V6_BLOCKED);
    }

    /** @param list<string> $cidrs */
    private static function inAny(string $packed, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netPacked = (string) inet_pton($net);
            if (strlen($netPacked) !== strlen($packed)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xff << (8 - $rest)) & 0xff;
            if ((ord($packed[$bytes]) & $mask) === (ord($netPacked[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
