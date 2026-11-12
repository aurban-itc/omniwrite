<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Support;

use OmniWrite\Net\FetchException;
use OmniWrite\Net\PageFetcher;

/** Liefert vorbereitete Seiten je Adresse; unbekannte Adressen scheitern wie ein nicht erreichbarer Host. */
final class FakePageFetcher implements PageFetcher
{
    /** @var array<string, string> */
    public array $pages = [];
    /** @var list<string> */
    public array $requested = [];

    public function fetch(string $url): array
    {
        $this->requested[] = $url;
        if (!isset($this->pages[$url])) {
            throw new FetchException('err.fetch_failed', 'unknown ' . $url);
        }
        return ['url' => $url, 'body' => $this->pages[$url]];
    }
}
