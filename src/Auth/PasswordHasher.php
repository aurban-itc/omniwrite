<?php
declare(strict_types=1);

namespace OmniWrite\Auth;

final class PasswordHasher
{
    private ?string $dummy = null;

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /** Gleicht die Laufzeit aus, wenn die E-Mail unbekannt ist (kein Nutzer-Enumeration per Timing). */
    public function burn(string $password): void
    {
        $this->dummy ??= $this->hash('omniwrite-dummy');
        password_verify($password, $this->dummy);
    }
}
