<?php

declare(strict_types=1);

namespace App\Module\Identity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Employee or administrator account (ACC-LIFE). The login identifier is the company e-mail address; the
 * administrator never sees the password (only an Argon2id hash is stored). Deletion keeps a pseudonymous row
 * so tickets and audit records stay attributable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'account')]
class Account implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $email;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, enumType: Role::class)]
    private Role $role;

    #[ORM\Column(type: Types::TEXT, enumType: AccountStatus::class)]
    private AccountStatus $status = AccountStatus::Active;

    #[ORM\Column(name: 'password_hash', type: Types::TEXT, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(name: 'rep_code', type: Types::TEXT, nullable: true)]
    private ?string $repCode;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $shared;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'password_changed_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $passwordChangedAt;

    #[ORM\Column(name: 'last_login_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(string $email, string $name, Role $role, ?string $repCode, string $passwordHash, \DateTimeImmutable $now, bool $shared = false)
    {
        if ((Role::Rep === $role) !== (null !== $repCode)) {
            throw new \InvalidArgumentException('A representative account needs exactly one representative code; an administrator has none');
        }
        $this->id = Uuid::v7();
        $this->email = self::normalizeEmail($email);
        $this->name = $name;
        $this->role = $role;
        $this->repCode = $repCode;
        $this->passwordHash = $passwordHash;
        $this->createdAt = $now;
        $this->passwordChangedAt = $now;
        $this->shared = $shared;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function role(): Role
    {
        return $this->role;
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    public function repCode(): ?string
    {
        return $this->repCode;
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    public function isActive(): bool
    {
        return AccountStatus::Active === $this->status;
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function passwordChangedAt(): \DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function changePassword(string $hash, \DateTimeImmutable $now): void
    {
        $this->passwordHash = $hash;
        $this->passwordChangedAt = $now;
    }

    public function block(): void
    {
        $this->status = AccountStatus::Blocked;
    }

    public function unblock(): void
    {
        $this->status = AccountStatus::Active;
    }

    /**
     * Removes access and personal login data; the row stays as a pseudonymous reference for history (ACC-LIFE).
     */
    public function delete(\DateTimeImmutable $now): void
    {
        $this->status = AccountStatus::Deleted;
        $this->email = 'deleted-'.$this->id->toRfc4122().'@invalid';
        $this->name = 'Usunięte konto';
        $this->passwordHash = null;
        $this->repCode = null;
        $this->deletedAt = $now;
    }

    public function recordLogin(\DateTimeImmutable $now): void
    {
        $this->lastLoginAt = $now;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getRoles(): array
    {
        return [$this->role->securityRole()];
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
