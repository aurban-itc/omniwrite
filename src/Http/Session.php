<?php
declare(strict_types=1);

namespace OmniWrite\Http;

/** Dünner Wrapper um ein Array (in Produktion $_SESSION, in Tests ein lokales Array). */
final class Session
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array &$data)
    {
        $this->data = &$data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    /** Neue Session-ID nach Login/Logout (Session Fixation). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function clear(): void
    {
        $this->data = [];
        $this->regenerate();
    }
}
