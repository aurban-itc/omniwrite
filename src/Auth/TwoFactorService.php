<?php
declare(strict_types=1);

namespace OmniWrite\Auth;

use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\TotpRepository;
use PDO;

final class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(
        private readonly PDO $pdo,
        private readonly TotpRepository $totp,
        private readonly SecretBox $box,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {
    }

    public function isEnabled(User $user): bool
    {
        return $this->totp->isEnabled($user->id);
    }

    /** Einrichtung der Pflicht-2FA: Superadmins. */
    public function isRequired(User $user): bool
    {
        return $user->isSuperadmin;
    }

    /**
     * Daten für die Einrichtungsseite: ein bereits begonnenes (unbestätigtes) Secret bleibt beim Neuladen
     * gleich, sonst wird eines erzeugt.
     *
     * @return array{secret: string, uri: string}
     */
    public function setupInfo(User $user): array
    {
        $row = $this->totp->find($user->id);
        if ($row === null) {
            return $this->beginSetup($user);
        }
        if ($row['confirmed_at'] !== null) {
            throw new AccessDeniedException('2FA ist bereits eingerichtet');
        }
        $secret = $this->box->decrypt($row['secret_enc']);
        return ['secret' => $secret, 'uri' => Totp::provisioningUri($secret, $user->email)];
    }

    /**
     * Neues Secret für die Einrichtung (ersetzt eine unbestätigte).
     *
     * @return array{secret: string, uri: string}
     */
    public function beginSetup(User $user): array
    {
        $secret = Totp::generateSecret();
        if (!$this->totp->begin($user->id, $this->box->encrypt($secret))) {
            throw new AccessDeniedException('2FA ist bereits eingerichtet');
        }
        return ['secret' => $secret, 'uri' => Totp::provisioningUri($secret, $user->email)];
    }

    /**
     * Bestätigt die Einrichtung mit einem ersten Code. Gibt die Recovery-Codes im Klartext zurück
     * (einmalig anzeigen; in der Datenbank liegen nur Hashes).
     *
     * @return list<string>
     */
    public function confirmSetup(User $user, string $code, int $now, ?string $ip = null): array
    {
        $row = $this->totp->find($user->id);
        if ($row === null || $row['confirmed_at'] !== null) {
            throw new AccessDeniedException('Keine offene 2FA-Einrichtung');
        }
        $step = Totp::verify($this->box->decrypt($row['secret_enc']), $code, $now);
        if ($step === null) {
            throw new ValidationException(['code' => 'err.code_invalid']);
        }
        $codes = $this->newRecoveryCodes();
        $this->pdo->beginTransaction();
        try {
            if (!$this->totp->confirm($user->id, $step, gmdate('Y-m-d H:i:s', $now))) {
                // Eine parallele Bestätigung war schneller: deren Recovery-Codes bleiben gültig.
                $this->pdo->rollBack();
                throw new AccessDeniedException('Keine offene 2FA-Einrichtung');
            }
            $this->totp->replaceRecoveryCodes($user->id, array_map($this->hashCode(...), $codes));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit->log('auth.2fa_enabled', $user->id, null, [], $ip);
        return $codes;
    }

    /**
     * Prüft den zweiten Faktor beim Login: TOTP-Code oder Recovery-Code. Rate-limitiert pro Nutzer und IP.
     *
     * @throws AccessDeniedException bei zu vielen Fehlversuchen
     */
    public function verifyLogin(User $user, string $input, string $ip, int $now): bool
    {
        $key = 'totp:' . $user->id;
        if ($this->limiter->isBlocked($key, $ip, $now)) {
            throw new AccessDeniedException('Zu viele Versuche');
        }
        $this->limiter->record($key, $ip, false, $now); // vor der Prüfung verbuchen (parallele Anfragen)

        $ok = $this->checkTotp($user, $input, $now) || $this->checkRecoveryCode($user, $input, $now);
        if (!$ok) {
            $this->audit->log('auth.2fa_failed', $user->id, null, [], $ip);
            return false;
        }
        $this->limiter->clear($key);
        $this->audit->log('auth.2fa_ok', $user->id, null, [], $ip);
        return true;
    }

    private function checkTotp(User $user, string $input, int $now): bool
    {
        $row = $this->totp->find($user->id);
        if ($row === null || $row['confirmed_at'] === null) {
            return false;
        }
        $step = Totp::verify($this->box->decrypt($row['secret_enc']), $input, $now);
        // Jeder Zeitschritt gilt nur einmal: abgefangene Codes lassen sich nicht wiederverwenden.
        return $step !== null && $this->totp->advanceStep($user->id, $step);
    }

    private function checkRecoveryCode(User $user, string $input, int $now): bool
    {
        $normalized = strtolower(preg_replace('/[\s-]+/', '', $input) ?? '');
        if (!preg_match('/^[a-z0-9]{10}$/', $normalized)) {
            return false;
        }
        return $this->totp->consumeRecoveryCode($user->id, $this->hashCode($normalized), gmdate('Y-m-d H:i:s', $now));
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = strtolower(substr(Totp::base32Encode(random_bytes(7)), 0, 10));
        }
        return $codes;
    }

    private function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }
}
