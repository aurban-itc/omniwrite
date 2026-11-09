<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use RuntimeException;

/** Stunden- oder Monatslimit erreicht. Die Meldung ist ein Übersetzungsschlüssel. */
final class UsageLimitException extends RuntimeException
{
}
