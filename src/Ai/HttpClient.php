<?php
declare(strict_types=1);

namespace OmniWrite\Ai;

/** Dünne HTTP-Schicht für die KI-Anbieter, damit Tests ohne Netzwerk laufen. */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string} status 0 = keine Antwort
     */
    public function postJson(string $url, array $headers, string $json, int $timeoutSeconds): array;
}
