<?php
declare(strict_types=1);

namespace OmniWrite\Push;

interface PushConnector
{
    /**
     * Beitrag zustellen (neu oder geändert). $prevRef: Kennung der letzten Zustellung ('' = noch nie).
     *
     * @param array<string, mixed> $cfg entschlüsselte Einstellungen @param array<string, mixed> $site @param array<string, mixed> $payload
     * @return string neue Kennung der Zustellung (z. B. Dateipfad)
     * @throws PushException
     */
    public function publish(array $cfg, array $site, array $payload, string $prevRef): string;

    /**
     * Beitrag entfernen (zurückgezogen).
     *
     * @param array<string, mixed> $cfg @param array<string, mixed> $site @param array<string, mixed> $payload nur id, slug, title
     * @throws PushException
     */
    public function remove(array $cfg, array $site, array $payload, string $prevRef): void;
}
