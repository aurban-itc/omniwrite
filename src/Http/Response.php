<?php
declare(strict_types=1);

namespace OmniWrite\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Strict-Transport-Security' => 'max-age=31536000',
            // img-src https: für die Titelbild-Vorschau im Editor (Bilder liegen auch auf fremden https-Hosts).
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' https:; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'",
        ], $body);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff'], $body);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function xml(string $body, int $status = 200, string $type = 'application/xml'): self
    {
        return new self($status, [
            'Content-Type' => $type . '; charset=utf-8', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300',
        ], $body);
    }

    /** @param array<string, string> $headers ersetzt/ergänzt Header */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $headers + $this->headers, $this->body);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, ['Location' => $location], '');
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
