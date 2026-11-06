<?php
declare(strict_types=1);

namespace OmniWrite\Auth;

use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\Role;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\UserRepository;
use PDO;

final class AuthService
{
    public const MIN_PASSWORD = 10;
    public const LOCALES = ['de', 'en'];

    /** @param 'open'|'approval'|'closed' $registrationMode */
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
        private readonly string $registrationMode = 'closed',
    ) {
    }

    public function registrationEnabled(): bool
    {
        return in_array($this->registrationMode, ['open', 'approval'], true);
    }

    /**
     * Legt Nutzer, Account und Owner-Mitgliedschaft an. Im Modus 'approval' bleibt der Nutzer
     * 'pending', bis der Superadmin ihn freischaltet.
     *
     * @param array<string, mixed> $input email, password, name, account_name, locale
     */
    public function register(array $input, ?string $ip = null): User
    {
        if (!$this->registrationEnabled()) {
            throw new AccessDeniedException('Registrierung ist deaktiviert');
        }
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $name = trim((string) ($input['name'] ?? ''));
        $accountName = trim((string) ($input['account_name'] ?? ''));
        $locale = (string) ($input['locale'] ?? 'de');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors['email'] = 'err.email_invalid';
        } elseif ($this->users->emailExists($email)) {
            $errors['email'] = 'err.email_taken';
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            $errors['password'] = 'err.password_short';
        }
        if ($name === '' || mb_strlen($name) > 190) {
            $errors['name'] = 'err.name_required';
        }
        if ($accountName === '' || mb_strlen($accountName) > 190) {
            $errors['account_name'] = 'err.account_name_required';
        }
        if (!in_array($locale, self::LOCALES, true)) {
            $locale = 'de';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $status = $this->registrationMode === 'approval' ? 'pending' : 'active';
        $this->pdo->beginTransaction();
        try {
            $userId = $this->users->create($email, $this->hasher->hash($password), $name, $locale, $status);
            $accountId = $this->accounts->create($accountName);
            $this->accounts->addMember($accountId, $userId, Role::Owner);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit->log('user.register', $userId, $accountId, ['status' => $status], $ip);
        return $this->users->find($userId) ?? throw new \LogicException('Nutzer fehlt nach dem Anlegen');
    }

    /** CLI: Superadmin ohne Account anlegen. */
    public function createSuperadmin(string $email, string $name, string $password): User
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => 'err.email_invalid']);
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new ValidationException(['password' => 'err.password_short']);
        }
        if ($this->users->emailExists($email)) {
            throw new ValidationException(['email' => 'err.email_taken']);
        }
        $id = $this->users->create($email, $this->hasher->hash($password), $name, 'de', 'active', true);
        $this->audit->log('user.create_superadmin', $id);
        return $this->users->find($id) ?? throw new \LogicException('Nutzer fehlt nach dem Anlegen');
    }

    /**
     * @return User|null null bei falschen Zugangsdaten oder nicht aktivem Konto
     * @throws AccessDeniedException bei Rate-Limit
     */
    public function attempt(string $email, string $password, string $ip, ?int $now = null): ?User
    {
        $now ??= time();
        $email = strtolower(trim($email));
        if ($this->limiter->isBlocked($email, $ip, $now)) {
            throw new AccessDeniedException('Zu viele Versuche');
        }
        $row = $this->users->findRowByEmail($email);
        if ($row === null) {
            $this->hasher->burn($password);
        }
        $ok = $row !== null && $this->hasher->verify($password, (string) $row['password_hash']);
        $user = $ok ? User::fromRow($row) : null;
        $accepted = $user !== null && $user->isActive();

        $this->limiter->record($email, $ip, $accepted, $now);
        if (!$accepted || $user === null) {
            $this->audit->log('auth.login_failed', $user?->id, null, ['email' => $email], $ip);
            return null;
        }
        $this->limiter->clear($email);
        if ($this->hasher->needsRehash((string) $row['password_hash'])) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }
        $this->users->touchLogin($user->id, gmdate('Y-m-d H:i:s', $now));
        $this->audit->log('auth.login', $user->id, null, [], $ip);
        return $user;
    }
}
