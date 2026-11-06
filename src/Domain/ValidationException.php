<?php
declare(strict_types=1);

namespace OmniWrite\Domain;

use RuntimeException;

/** Eingabefehler: Feldname => Übersetzungsschlüssel. */
final class ValidationException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed: ' . implode(', ', array_keys($errors)));
    }
}
