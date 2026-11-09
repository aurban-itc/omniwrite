<?php
declare(strict_types=1);

namespace OmniWrite\Http;

/**
 * Erzwingt HTTPS in PHP statt in .htaccess, damit die Vertrauensgrenze zum Proxy konfigurierbar ist:
 * X-Forwarded-Proto wird nur ausgewertet, wenn app.trust_forwarded_proto bewusst aktiviert wurde
 * (TLS-beendender Proxy, der den Header selbst setzt bzw. überschreibt). Sonst zählt nur HTTPS=on.
 */
final class HttpsPolicy
{
    /** @param array<string, mixed> $server $_SERVER */
    public static function isSecure(array $server, bool $trustForwardedProto): bool
    {
        if (($server['HTTPS'] ?? '') === 'on') {
            return true;
        }
        return $trustForwardedProto && ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Ziel für den Redirect auf HTTPS oder null, wenn nichts zu tun ist. Das Ziel entsteht aus der
     * konfigurierten base_url, nie aus dem Host-Header (kein offener Redirect).
     *
     * @param array<string, mixed> $server
     */
    public static function redirectTarget(array $server, string $baseUrl, bool $force, bool $trustForwardedProto): ?string
    {
        if (!$force || !str_starts_with($baseUrl, 'https://') || self::isSecure($server, $trustForwardedProto)) {
            return null;
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        return rtrim($baseUrl, '/') . ($uri !== '' && $uri[0] === '/' ? $uri : '/');
    }

    /**
     * Redirect für HTTP-Aufrufe einer verifizierten eigenen Domain: auf deren eigene https-Adresse, nicht auf das Portal.
     * $verifiedHost stammt aus der Datenbank, nie aus dem Host-Header.
     *
     * @param array<string, mixed> $server
     */
    public static function customDomainTarget(array $server, string $verifiedHost, bool $force, bool $trustForwardedProto): ?string
    {
        if (!$force || self::isSecure($server, $trustForwardedProto)) {
            return null;
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        return 'https://' . $verifiedHost . ($uri !== '' && $uri[0] === '/' ? $uri : '/');
    }
}
