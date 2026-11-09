<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

use PDO;

/**
 * Einmal-Links der Review-Mail. Bewusst nicht an eine Site gebunden: Der Token selbst bestimmt die Site
 * (danach läuft alles über deren SiteContext). Gespeichert wird nur der SHA-256 des Tokens.
 */
final class ReviewTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(int $siteId, int $postId, string $email, string $tokenHash, string $expiresAt): void
    {
        $this->pdo->prepare('INSERT INTO review_tokens (site_id, post_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$siteId, $postId, $email, $tokenHash, $expiresAt]);
    }

    /** @return array<string, mixed>|null nur, wenn noch nicht abgelaufen */
    public function findValid(string $tokenHash, string $now): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM review_tokens WHERE token_hash = ? AND expires_at > ?');
        $stmt->execute([$tokenHash, $now]);
        return $stmt->fetch() ?: null;
    }

    public function deleteExpired(string $now): void
    {
        $this->pdo->prepare('DELETE FROM review_tokens WHERE expires_at <= ?')->execute([$now]);
    }
}
