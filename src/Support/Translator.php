<?php
declare(strict_types=1);

namespace OmniWrite\Support;

final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    public function __construct(private readonly string $dir, private readonly string $fallback = 'de')
    {
    }

    /** @param array<string, string|int> $params */
    public function t(string $locale, string $key, array $params = []): string
    {
        $text = $this->load($locale)[$key] ?? $this->load($this->fallback)[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** @return array<string, string> */
    private function load(string $locale): array
    {
        if (!isset($this->cache[$locale])) {
            $file = $this->dir . '/' . preg_replace('/[^a-z]/', '', $locale) . '.php';
            $this->cache[$locale] = is_file($file) ? (require $file) : [];
        }
        return $this->cache[$locale];
    }
}
