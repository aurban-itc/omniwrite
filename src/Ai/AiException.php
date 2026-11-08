<?php
declare(strict_types=1);

namespace OmniWrite\Ai;

use RuntimeException;

/** Fehler eines KI-Aufrufs. Die Meldung ist ein Übersetzungsschlüssel (err.ai_*), nie ein Rohtext des Anbieters. */
final class AiException extends RuntimeException
{
}
