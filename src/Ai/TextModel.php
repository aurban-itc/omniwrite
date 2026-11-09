<?php
declare(strict_types=1);

namespace OmniWrite\Ai;

interface TextModel
{
    public function isConfigured(): bool;

    /**
     * Ein Aufruf, der ein Werkzeug erzwingt, und liefert dessen Eingabe zurück.
     *
     * @param array<string, mixed> $tool Werkzeugdefinition (name, description, input_schema)
     * @return array<string, mixed>
     * @throws AiException
     */
    public function callTool(string $system, string $user, array $tool, int $maxTokens = 4096): array;
}
