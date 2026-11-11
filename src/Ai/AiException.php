<?php
declare(strict_types=1);

namespace OmniWrite\Ai;

use RuntimeException;

/** Fehler eines KI-Aufrufs. Die Meldung ist ein Übersetzungsschlüssel (err.ai_*), nie ein Rohtext des Anbieters. */
final class AiException extends RuntimeException
{
    /** Technische Anbieter-Meldung (HTTP-Status, Text), nur für Superadmins sichtbar. Nie Schlüssel oder Prompt. */
    public readonly string $detail;

    public function __construct(string $message, string $detail = '')
    {
        parent::__construct($message);
        $this->detail = (string) preg_replace('/[^\P{C}\t]/u', '?', mb_convert_encoding($detail, 'UTF-8', 'UTF-8'));
    }
}
