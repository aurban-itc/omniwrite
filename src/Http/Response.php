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
            'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'",
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
