<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Auth\AuthService;
use OmniWrite\Auth\PasswordHasher;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\Role;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\UserRepository;
use PDO;

/** Kunden- und Nutzerverwaltung des Superadmins. Der Aufrufer (Route mit Guard 'superadmin') hat die Berechtigung schon geprüft. */
final class AdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Legt einen Account samt Owner-Nutzer an (sofort aktiv, mit dem vergebenen Startpasswort).
     *
     * @param array<string, mixed> $input account_name, email, name, password, locale
     * @return int Account-ID
     */
    public function createAccount(User $actor, array $input, ?string $ip = null): int
    {
        $accountName = trim((string) ($input['account_name'] ?? ''));
        // Der erste Nutzer ist optional (Admin betreut den Account selbst): ganz leer = kein Nutzer, sonst vollständig prüfen.
        $withOwner = trim((string) ($input['email'] ?? '')) !== '' || trim((string) ($input['name'] ?? '')) !== '' || (string) ($input['password'] ?? '') !== '';
        $errors = $withOwner ? $this->validateNewUser($input) : [];
        if ($accountName === '' || mb_strlen($accountName) > 190) {
            $errors['account_name'] = 'err.account_name_required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->pdo->beginTransaction();
        try {
            $userId = $withOwner ? $this->createUser($input) : null;
            $accountId = $this->accounts->create($accountName);
            if ($userId !== null) {
                $this->accounts->addMember($accountId, $userId, Role::Owner);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit->log('admin.account_create', $actor->id, $accountId, ['owner_user_id' => $userId], $ip);
        return $accountId;
    }

    public function renameAccount(User $actor, int $accountId, string $name, ?string $ip = null): void
    {
        $this->requireAccount($accountId);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 190) {
            throw new ValidationException(['account_name' => 'err.account_name_required']);
        }
        $this->accounts->rename($accountId, $name);
        $this->audit->log('admin.account_rename', $actor->id, $accountId, [], $ip);
    }

    /**
     * Fügt dem Account einen Nutzer hinzu: ein neuer (mit Startpasswort) oder ein bestehender Kunden-Nutzer
     * (gleiche E-Mail, dann ohne Passwortangabe).
     *
     * @param array<string, mixed> $input email, name, password, locale, role
     */
    public function addMember(User $actor, int $accountId, array $input, ?string $ip = null): int
    {
        $this->requireAccount($accountId);
        $role = Role::tryFrom((string) ($input['role'] ?? '')) ?? null;
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $existing = filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->users->findRowByEmail($email) : null;
        if ($existing !== null) {
            $errors = [];
            if ((bool) $existing['is_superadmin']) {
                $errors['email'] = 'err.email_taken';
            } elseif ($this->accounts->roleOf($accountId, (int) $existing['id']) !== null) {
                $errors['email'] = 'err.already_member';
            }
            if ($role === null) {
                $errors['role'] = 'err.role_invalid';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $userId = (int) $existing['id'];
            $this->accounts->addMember($accountId, $userId, $role);
        } else {
            $errors = $this->validateNewUser($input);
            if ($role === null) {
                $errors['role'] = 'err.role_invalid';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $this->pdo->beginTransaction();
            try {
                $userId = $this->createUser($input);
                $this->accounts->addMember($accountId, $userId, $role);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }
        $this->audit->log('admin.member_add', $actor->id, $accountId, ['user_id' => $userId, 'role' => $role->value], $ip);
        return $userId;
    }

    public function setMemberRole(User $actor, int $accountId, int $userId, string $role, ?string $ip = null): void
    {
        $new = Role::tryFrom($role) ?? throw new ValidationException(['role' => 'err.role_invalid']);
        // Prüfung und Änderung unter einer Sperre pro Account, damit zwei gleichzeitige Änderungen nicht beide den letzten Owner entfernen.
        $this->users->withLock('omniwrite-owners-' . $accountId, function () use ($accountId, $userId, $new): void {
            $current = $this->accounts->roleOf($accountId, $userId) ?? throw new NotFoundException('Mitglied nicht gefunden');
            if ($current === Role::Owner && $new !== Role::Owner && $this->accounts->ownerCount($accountId) <= 1) {
                throw new ValidationException(['role' => 'err.last_owner']);
            }
            $this->accounts->setRole($accountId, $userId, $new);
        });
        $this->audit->log('admin.member_role', $actor->id, $accountId, ['user_id' => $userId, 'role' => $new->value], $ip);
    }

    public function removeMember(User $actor, int $accountId, int $userId, ?string $ip = null): void
    {
        $this->users->withLock('omniwrite-owners-' . $accountId, function () use ($accountId, $userId): void {
            $current = $this->accounts->roleOf($accountId, $userId) ?? throw new NotFoundException('Mitglied nicht gefunden');
            if ($current === Role::Owner && $this->accounts->ownerCount($accountId) <= 1) {
                throw new ValidationException(['role' => 'err.last_owner']);
            }
            $this->accounts->removeMember($accountId, $userId);
        });
        $this->audit->log('admin.member_remove', $actor->id, $accountId, ['user_id' => $userId], $ip);
    }

    /** Status eines Kunden-Nutzers: 'active' (freischalten/entsperren) oder 'disabled' (sperren/ablehnen). Superadmins bleiben unberührt. */
    public function setUserStatus(User $actor, int $userId, string $status, ?string $ip = null): void
    {
        $user = $this->users->find($userId);
        if ($user === null || $user->isSuperadmin || !in_array($status, ['active', 'disabled'], true)) {
            throw new NotFoundException('Nutzer nicht gefunden');
        }
        $this->users->setStatus($userId, $status);
        $this->audit->log($status === 'active' ? 'user.activate' : 'admin.user_disable', $actor->id, null, ['target_user_id' => $userId], $ip);
    }

    public function resetPassword(User $actor, int $userId, string $password, ?string $ip = null): void
    {
        $user = $this->users->find($userId);
        if ($user === null || $user->isSuperadmin) {
            throw new NotFoundException('Nutzer nicht gefunden');
        }
        if (mb_strlen($password) < AuthService::MIN_PASSWORD) {
            throw new ValidationException(['password' => 'err.password_short']);
        }
        $this->users->updatePasswordHash($userId, $this->hasher->hash($password));
        $this->audit->log('admin.password_reset', $actor->id, null, ['target_user_id' => $userId], $ip);
    }

    /** @return array{id: int, name: string} */
    public function requireAccount(int $accountId): array
    {
        return $this->accounts->find($accountId) ?? throw new NotFoundException('Account nicht gefunden');
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function validateNewUser(array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors['email'] = 'err.email_invalid';
        } elseif ($this->users->emailExists($email)) {
            $errors['email'] = 'err.email_taken';
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 190) {
            $errors['name'] = 'err.name_required';
        }
        if (mb_strlen((string) ($input['password'] ?? '')) < AuthService::MIN_PASSWORD) {
            $errors['password'] = 'err.password_short';
        }
        return $errors;
    }

    /** @param array<string, mixed> $input */
    private function createUser(array $input): int
    {
        $locale = (string) ($input['locale'] ?? 'de');
        return $this->users->create(
            strtolower(trim((string) $input['email'])),
            $this->hasher->hash((string) $input['password']),
            trim((string) $input['name']),
            in_array($locale, AuthService::LOCALES, true) ? $locale : 'de',
            'active',
        );
    }
}
