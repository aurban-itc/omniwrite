<?php
declare(strict_types=1);

namespace OmniWrite\Domain;

use RuntimeException;

/** Objekt existiert nicht oder gehört zu einer fremden Site (wird bewusst nicht unterschieden). */
final class NotFoundException extends RuntimeException
{
}
