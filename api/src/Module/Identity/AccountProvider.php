<?php

declare(strict_types=1);

namespace App\Module\Identity;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads only active accounts. Refreshing the user on every request means a blocked or deleted account loses its
 * session at the next request even before its session rows are removed (ACC-1, ADR 0003).
 *
 * @implements UserProviderInterface<Account>
 */
final class AccountProvider implements UserProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function loadUserByIdentifier(string $identifier): Account
    {
        $account = $this->findByEmail($identifier);
        if (null === $account || !$account->isActive()) {
            throw new UserNotFoundException();
        }

        return $account;
    }

    public function refreshUser(UserInterface $user): Account
    {
        if (!$user instanceof Account) {
            throw new UnsupportedUserException();
        }
        $fresh = $this->em->find(Account::class, $user->id());
        if (null === $fresh) {
            throw new UserNotFoundException();
        }
        $this->em->refresh($fresh);
        if (!$fresh->isActive()) {
            throw new UserNotFoundException();
        }

        return $fresh;
    }

    public function supportsClass(string $class): bool
    {
        return Account::class === $class || is_subclass_of($class, Account::class);
    }

    public function findByEmail(string $email): ?Account
    {
        $account = $this->em->createQuery('SELECT a FROM '.Account::class.' a WHERE LOWER(a.email) = :email')
            ->setParameter('email', Account::normalizeEmail($email))
            ->getOneOrNullResult();

        return $account instanceof Account ? $account : null;
    }
}
