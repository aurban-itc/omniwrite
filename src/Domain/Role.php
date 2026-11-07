<?php
declare(strict_types=1);

namespace OmniWrite\Domain;

/** Rolle eines Nutzers in einem Account. Superadmin ist ein Flag am Nutzer, keine Account-Rolle. */
enum Role: string
{
    case Owner = 'owner';
    case Editor = 'editor';

    public function canManageSites(): bool
    {
        return $this === self::Owner;
    }

    /** Beiträge und Themen schreiben. */
    public function canWriteContent(): bool
    {
        return true;
    }

    /** Veröffentlichen, ablehnen, löschen und Site-Einstellungen: nur Owner (Veröffentlichung ist immer eine bewusste Freigabe). */
    public function canPublish(): bool
    {
        return $this === self::Owner;
    }
}
