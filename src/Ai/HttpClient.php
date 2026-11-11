<?php
declare(strict_types=1);

namespace OmniWrite\Ai;

/** Dünne HTTP-Schicht für KI-Anbieter und Push-Ziele, damit Tests ohne Netzwerk laufen. */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string} status 0 = keine Antwort
     */
    public function postJson(string $url, array $headers, string $json, int $timeoutSeconds): array;

    /**
     * Allgemeine Anfrage nur über HTTPS, ohne Weiterleitungen. $pinIp legt die Adresse des Hosts fest (gegen DNS-Wechsel nach der Prüfung).
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string} status 0 = keine Antwort
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds, ?string $pinIp = null): array;
}
