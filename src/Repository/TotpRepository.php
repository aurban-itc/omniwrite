<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use PDO;

/** TOTP-Secret (verschlüsselt) und Recovery-Codes (gehasht) pro Nutzer. Globale Tabellen, nicht site-gebunden. */
final class TotpRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{secret_enc: string, confirmed_at: ?string, last_step: ?int}|null */
    public function find(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT secret_enc, confirmed_at, last_step FROM user_totp WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? ['secret_enc' => (string) $row['secret_enc'], 'confirmed_at' => $row['confirmed_at'], 'last_step' => $row['last_step'] === null ? null : (int) $row['last_step']] : null;
    }

    public function isEnabled(int $userId): bool
    {
        return ($this->find($userId)['confirmed_at'] ?? null) !== null;
    }

    /** Startet (oder ersetzt) eine noch nicht bestätigte Einrichtung. Ein bestätigtes Secret bleibt unangetastet. */
    public function begin(int $userId, string $secretEnc): bool
    {
        if ($this->isEnabled($userId)) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM user_totp WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('INSERT INTO user_totp (user_id, secret_enc) VALUES (?, ?)')->execute([$userId, $secretEnc]);
        return true;
    }

    public function confirm(int $userId, int $step, string $when): void
    {
        $this->pdo->prepare('UPDATE user_totp SET confirmed_at = ?, last_step = ? WHERE user_id = ?')
            ->execute([$when, $step, $userId]);
    }

    /** Atomar: nur wenn der Schritt neuer ist als der zuletzt benutzte (Replay-Schutz). */
    public function advanceStep(int $userId, int $step): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_totp SET last_step = ? WHERE user_id = ? AND (last_step IS NULL OR last_step < ?)'
        );
        $stmt->execute([$step, $userId, $step]);
        return $stmt->rowCount() > 0;
    }

    /** @param list<string> $hashes */
    public function replaceRecoveryCodes(int $userId, array $hashes): void
    {
        $this->pdo->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
        $insert = $this->pdo->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
        foreach ($hashes as $hash) {
            $insert->execute([$userId, $hash]);
        }
    }

    /** Verbraucht einen Code atomar; true nur beim ersten Mal. */
    public function consumeRecoveryCode(int $userId, string $hash, string $when): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL'
        );
        $stmt->execute([$when, $userId, $hash]);
        return $stmt->rowCount() > 0;
    }
}
