<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Audit\AuditLog;
use App\Module\Identity\Account;
use App\Module\Identity\AccountException;
use App\Module\Identity\AccountService;
use App\Module\Identity\AccountStatus;
use App\Module\Report\Business\BusinessApi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Administrator account management (ROLE_ADMIN via access_control). Never returns passwords or hashes.
 */
#[Route('/api/v1/admin')]
final class AdminAccountController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountService $accounts,
    ) {
    }

    #[Route('/accounts', name: 'api_admin_accounts', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $accounts = $this->em->createQuery('SELECT a FROM '.Account::class.' a WHERE a.status <> :deleted ORDER BY a.role, a.name')
            ->setParameter('deleted', AccountStatus::Deleted)
            ->getResult();
        \assert(\is_array($accounts));

        return new JsonResponse(['items' => array_map(self::view(...), array_values(array_filter($accounts, static fn (mixed $a): bool => $a instanceof Account)))]);
    }

    #[Route('/accounts', name: 'api_admin_accounts_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] Account $actor): JsonResponse
    {
        $body = AuthController::json($request);
        $account = $this->accounts->createRepresentative($actor, AuthController::str($body, 'name'), AuthController::str($body, 'email'), AuthController::str($body, 'repCode'));

        return new JsonResponse(self::view($account), 201);
    }

    #[Route('/accounts/{id}/block', name: 'api_admin_accounts_block', methods: ['POST'])]
    public function block(string $id, #[CurrentUser] Account $actor): JsonResponse
    {
        $account = $this->find($id);
        $this->accounts->block($actor, $account);

        return new JsonResponse(self::view($account));
    }

    #[Route('/accounts/{id}/unblock', name: 'api_admin_accounts_unblock', methods: ['POST'])]
    public function unblock(string $id, #[CurrentUser] Account $actor): JsonResponse
    {
        $account = $this->find($id);
        $this->accounts->unblock($actor, $account);

        return new JsonResponse(self::view($account));
    }

    #[Route('/accounts/{id}', name: 'api_admin_accounts_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] Account $actor): Response
    {
        $this->accounts->delete($actor, $this->find($id));

        return new Response(null, 204);
    }

    #[Route('/sales-reps', name: 'api_admin_sales_reps', methods: ['GET'])]
    public function salesReps(BusinessApi $business): JsonResponse
    {
        $taken = $this->em->createQuery('SELECT a.repCode FROM '.Account::class.' a WHERE a.repCode IS NOT NULL')->getSingleColumnResult();

        return new JsonResponse(['items' => array_map(
            static fn (string $code): array => ['code' => $code, 'taken' => \in_array($code, $taken, true)],
            $business->salesReps(),
        )]);
    }

    #[Route('/audit', name: 'api_admin_audit', methods: ['GET'])]
    public function audit(AuditLog $audit): JsonResponse
    {
        return new JsonResponse(['items' => $audit->latest()]);
    }

    private function find(string $id): Account
    {
        $account = Uuid::isValid($id) ? $this->em->find(Account::class, Uuid::fromString($id)) : null;

        return $account instanceof Account && AccountStatus::Deleted !== $account->status() ? $account : throw new AccountException('not_found', 404);
    }

    /**
     * @return array<string, string|bool|null>
     */
    private static function view(Account $a): array
    {
        return [
            'id' => $a->id()->toRfc4122(),
            'name' => $a->name(),
            'email' => $a->email(),
            'role' => $a->role()->value,
            'repCode' => $a->repCode(),
            'status' => $a->status()->value,
            'shared' => $a->isShared(),
            'lastLoginAt' => $a->lastLoginAt()?->format(\DATE_ATOM),
        ];
    }
}
