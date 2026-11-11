<?php
declare(strict_types=1);

namespace OmniWrite\Net;

/** Ruft eine fremde Webseite ab. Austauschbar, damit Tests ohne Netz laufen. */
interface PageFetcher
{
    /**
     * @return array{url: string, body: string} Endadresse nach Weiterleitungen und HTML-Inhalt
     * @throws FetchException
     */
    public function fetch(string $url): array;
}
