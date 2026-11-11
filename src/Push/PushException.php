<?php
declare(strict_types=1);

namespace OmniWrite\Push;

use RuntimeException;

/** Zustellung fehlgeschlagen. $retryable: später erneut versuchen (Netz, 5xx, 429); sonst nur nach Änderung oder manuell. */
final class PushException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
