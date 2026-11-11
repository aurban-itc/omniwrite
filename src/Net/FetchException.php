<?php
declare(strict_types=1);

namespace OmniWrite\Net;

use RuntimeException;

/** Seitenabruf gescheitert. Die Meldung ist ein Übersetzungsschlüssel (err.fetch_*); $detail nur für Logs. */
final class FetchException extends RuntimeException
{
    public function __construct(string $message, public readonly string $detail = '')
    {
        parent::__construct($message);
    }
}
