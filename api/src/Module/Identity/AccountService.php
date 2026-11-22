<?php

declare(strict_types=1);

namespace App\Module\Identity;

use App\Module\Audit\AuditLog;
use App\Module\Report\Business\BusinessApi;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Account lifecycle (ACC-LIFE): create with a generated password sent to the employee, block (sessions revoked),
 * unblock, delete (pseudonymous history kept), password change and reset. Shared demo accounts cannot be changed.
 */
final class AccountService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountProvider $accounts,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordGenerator $passwords,
        private readonly AccountMailer $mailer,
        private readonly SessionRevoker $sessions,
        private readonly AuditLog $audit,
        private readonly BusinessApi $business,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createRepresentative(Account $actor, string $name, string $email, string $repCode): Account
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 120) {
            throw new AccountException('invalid_name');
        }
        if (false === filter_var(trim($email), \FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new AccountException('invalid_email');
        }
        if (1 !== preg_match('/^PH-\d{2}$/', $repCode) || !\in_array($repCode, $this->business->salesReps(), true)) {
            throw new AccountException('unknown_sales_rep');
        }
        if (null !== $this->accounts->findByEmail($email)) {
            throw new AccountException('email_taken', 409);
        }
        if (null !== $this->em->getRepository(Account::class)->findOneBy(['repCode' => $repCode])) {
            throw new AccountException('sales_rep_taken', 409);
        }

        $password = $this->passwords->generate();
        $account = new Account($email, $name, Role::Rep, $repCode, 'pending', $this->clock->now());
        $account->changePassword($this->hasher->hashPassword($account, $password), $this->clock->now());
        $this->em->persist($account);
        $this->em->flush();
        $this->audit->record(self::ref($actor), 'account.created', self::ref($account), ['rep_code' => $repCode]);
        $this->mailer->sendNewAccount($account, $password);

        return $account;
    }

    public function block(Account $actor, Account $account): void
    {
        $this->assertChangeable($actor, $account);
        $account->block();
        $this->em->flush();
        $revoked = $this->sessions->revokeAll($account);
        $this->audit->record(self::ref($actor), 'account.blocked', self::ref($account), ['sessions_revoked' => $revoked]);
    }

    public function unblock(Account $actor, Account $account): void
    {
        $this->assertChangeable($actor, $account);
        $account->unblock();
        $this->em->flush();
        $this->audit->record(self::ref($actor), 'account.unblocked', self::ref($account));
    }

    public function delete(Account $actor, Account $account): void
    {
        $this->assertChangeable($actor, $account);
        $this->sessions->revokeAll($account);
        $account->delete($this->clock->now());
        $this->em->flush();
        $this->audit->record(self::ref($actor), 'account.deleted', self::ref($account));
    }

    public function changePassword(Account $account, string $current, string $new, string $currentSessionId): void
    {
        if ($account->isShared()) {
            throw new AccountException('shared_account', 403);
        }
        if (!$this->hasher->isPasswordValid($account, $current)) {
            throw new AccountException('invalid_current_password');
        }
        if (mb_strlen($new) < 12 || mb_strlen($new) > 128) {
            throw new AccountException('weak_password');
        }
        $account->changePassword($this->hasher->hashPassword($account, $new), $this->clock->now());
        $this->em->flush();
        $this->sessions->revokeAll($account, $currentSessionId);
        $this->audit->record(self::ref($account), 'account.password_changed', self::ref($account));
    }

    /**
     * Same behaviour for unknown, blocked and shared accounts (no account enumeration); call after the response.
     */
    public function resetPassword(string $email): void
    {
        $account = $this->accounts->findByEmail($email);
        if (null === $account || !$account->isActive() || $account->isShared()) {
            return;
        }
        $password = $this->passwords->generate();
        $account->changePassword($this->hasher->hashPassword($account, $password), $this->clock->now());
        $this->em->flush();
        $this->sessions->revokeAll($account);
        $this->audit->record('self', 'account.password_reset', self::ref($account));
        $this->mailer->sendPasswordReset($account, $password);
    }

    public function recordLogin(Account $account): void
    {
        $account->recordLogin($this->clock->now());
        $this->em->flush();
        $this->audit->record(self::ref($account), 'auth.login', self::ref($account));
    }

    private function assertChangeable(Account $actor, Account $account): void
    {
        if ($account->isShared()) {
            throw new AccountException('shared_account', 403);
        }
        if ($account->id()->equals($actor->id())) {
            throw new AccountException('own_account', 403);
        }
        if (Role::Admin === $account->role()) {
            throw new AccountException('admin_account', 403);
        }
        if (AccountStatus::Deleted === $account->status()) {
            throw new AccountException('deleted_account', 404);
        }
    }

    public static function ref(Account $account): string
    {
        return 'account:'.$account->id()->toRfc4122();
    }
}
