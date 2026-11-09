<?php
declare(strict_types=1);

namespace OmniWrite\Service;

/**
 * Wie ein öffentlicher Blog erreichbar ist: Links innerhalb der Seite folgen dem Aufruf (/b/<slug> am Portal, Wurzel auf der eigenen Domain),
 * absolute Adressen (Sitemap, Feed, canonical) zeigen immer auf die bevorzugte Adresse (eigene Domain, sobald verifiziert).
 */
final class BlogView
{
    /**
     * @param array<string, mixed> $site
     * @param array<string, string> $theme
     */
    public function __construct(
        public readonly array $site,
        public readonly array $theme,
        public readonly string $linkPrefix,
        public readonly string $origin,
        public readonly string $canonicalPrefix,
    ) {
    }

    public function name(): string
    {
        return (string) $this->site['name'];
    }

    /** Relative Adresse für Links auf der Seite. */
    public function link(string $path = ''): string
    {
        return ($this->linkPrefix . $path) === '' ? '/' : $this->linkPrefix . $path;
    }

    /** Absolute bevorzugte Adresse (https). */
    public function abs(string $path = ''): string
    {
        return $this->origin . $this->canonicalPrefix . $path;
    }

    /** Bild-Adresse absolut machen (Pfade wie /media/… liegen auf dem Ursprung). */
    public function absMedia(string $src): string
    {
        return str_starts_with($src, '/') ? $this->origin . $src : $src;
    }
}
