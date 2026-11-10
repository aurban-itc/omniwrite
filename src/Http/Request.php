<?php
declare(strict_types=1);

namespace OmniWrite\Http;

use OmniWrite\Domain\User;

final class Request
{
    public ?User $user = null;
    /** Echter Superadmin, solange er als anderer Nutzer eingeloggt ist (Impersonation). */
    public ?User $impersonator = null;
    public string $locale = 'de';
    /** Site, die über eine verifizierte eigene Domain angesprochen wird (setzt der Kernel). @var array<string, mixed>|null */
    public ?array $publicSite = null;
    /** @var array<string, string> Pfadparameter aus dem Router, z. B. {id}. */
    public array $params = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly Session $session,
        public readonly string $ip = '0.0.0.0',
        public readonly bool $https = false,
        /** Host des Aufrufs, kleingeschrieben und ohne Port; leer = unbekannt (dann gilt das Portal). */
        public readonly string $host = '',
        /** Hochgeladene Dateien wie in $_FILES. @var array<string, mixed> */
        public readonly array $files = [],
    ) {
    }

    /** @param array<string, mixed> $session Referenz auf $_SESSION */
    public static function fromGlobals(array &$session): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $https = ($_SERVER['HTTPS'] ?? '') === 'on'; // X-Forwarded-Proto ist fälschbar und wird nicht beachtet
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            new Session($session),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            $https,
            strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))),
            $_FILES,
        );
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }
}
