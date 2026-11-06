<?php
declare(strict_types=1);

namespace OmniWrite\Domain;

use InvalidArgumentException;

/**
 * Der aktuelle Mandant (Site). Wird einmal pro Request/Cron-Lauf aufgelöst
 * (Session, Host, Token) und an Repositories übergeben, nie aus Nutzereingaben pro Abfrage.
 */
final class SiteContext
{
    public function __construct(public readonly int $siteId)
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('site_id muss positiv sein');
        }
    }
}
