<?php
declare(strict_types=1);

namespace OmniWrite\Core;

use RuntimeException;

/** Unveränderliche Konfiguration, geladen aus config/config.php. */
final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Konfiguration fehlt: $path (config.example.php kopieren)");
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new RuntimeException("Konfiguration muss ein Array liefern: $path");
        }
        return new self($values);
    }

    /** Punkt-Notation, z. B. get('db.dsn'). */
    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}
