<?php
declare(strict_types=1);

namespace OmniWrite\Net;

/** Ein einzelner GET ohne Weiterleitungen, gegen eine fest vorgegebene IP (die Prüfung hat der Aufrufer schon gemacht). */
interface PageTransport
{
    /**
     * @param string $ip Zieladresse, an die verbunden wird (Host und Port bleiben für Header und TLS maßgeblich)
     * @return array{status: int, location: string, contentType: string, body: string} status 0 = keine Antwort
     */
    public function get(string $url, string $host, int $port, string $ip, int $maxBytes, int $timeoutSeconds): array;
}
