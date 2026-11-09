<?php
declare(strict_types=1);

namespace OmniWrite\Ai;

interface ImageModel
{
    public function isConfigured(): bool;

    /** @return string Rohe Bilddaten (PNG/WebP/JPEG) @throws AiException */
    public function generate(string $prompt): string;
}
