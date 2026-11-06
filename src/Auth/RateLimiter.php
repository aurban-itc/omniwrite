<?php
declare(strict_types=1);

namespace OmniWrite\Auth;

use PDO;

/** Begrenzt fehlgeschlagene Logins pro E-Mail und pro IP in einem Zeitfenster. */
final class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxPerEmail = 5,
        private readonly int $maxPerIp = 20,
        private readonly int $windowSeconds = 900,
    ) {
    }

    public function isBlocked(string $email, string $ip, int $now): bool
    {
        $since = gmdate('Y-m-d H:i:s', $now - $this->windowSeconds);
        return $this->failures('email', $email, $since) >= $this->maxPerEmail
            || $this->failures('ip', $ip, $since) >= $this->maxPerIp;
    }

    public function record(string $email, string $ip, bool $succeeded, int $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO login_attempts (email, ip, succeeded, attempted_at) VALUES (?, ?, ?, ?)'
        )->execute([$email, $ip, $succeeded ? 1 : 0, gmdate('Y-m-d H:i:s', $now)]);
    }

    /** Nach erfolgreichem Login zählen frühere Fehlversuche dieser E-Mail nicht mehr. */
    public function clear(string $email): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE email = ? AND succeeded = 0')->execute([$email]);
    }

    private function failures(string $column, string $value, string $since): int
    {
        // $column ist eine interne Konstante ('email' | 'ip'), nie Nutzereingabe.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts WHERE $column = ? AND succeeded = 0 AND attempted_at >= ?"
        );
        $stmt->execute([$value, $since]);
        return (int) $stmt->fetchColumn();
    }
}
