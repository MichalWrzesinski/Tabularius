<?php

declare(strict_types=1);

namespace App\Module\Ticket\Http;

use App\Module\Identity\Account;
use App\Module\Identity\Http\AuthController;
use App\Module\Identity\Role;
use App\Module\Report\Business\BusinessApi;
use App\Module\Report\Business\DataSourceException;
use App\Module\Report\Profile\IntegrationProfile;
use App\Module\Shared\Http\Problem;
use App\Module\Shared\Refusal;
use App\Module\Ticket\TicketService;
use App\Module\Ticket\TicketStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Administrator ticket API (ROLE_ADMIN via access_control): list, details, manual creation, retry, reject, and the
 * catalog the creation form offers (operations, manufacturers, categories, the chosen owner's customers).
 */
#[Route('/api/v1/admin')]
final class AdminTicketController
{
    public function __construct(
        private readonly TicketStore $tickets,
        private readonly TicketService $service,
        private readonly TicketView $view,
    ) {
    }

    #[Route('/tickets', name: 'api_admin_tickets', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(['items' => array_map($this->view->summary(...), $this->tickets->list())]);
    }

    #[Route('/tickets/{id}', name: 'api_admin_ticket', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return new JsonResponse($this->detail($id));
    }

    #[Route('/tickets', name: 'api_admin_tickets_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] Account $admin, RateLimiterFactoryInterface $manualTicketLimiter): JsonResponse
    {
        if (!$manualTicketLimiter->create($admin->id()->toRfc4122())->consume()->isAccepted()) {
            return Problem::response(429, 'too_many_attempts', 'Too many tickets created, try again later');
        }
        $body = AuthController::json($request);
        $parameters = $body['parameters'] ?? null;
        if (!\is_array($parameters)) {
            throw new BadRequestHttpException('Missing field parameters');
        }
        $id = $this->service->createManual($admin, AuthController::str($body, 'ownerId'), AuthController::str($body, 'operation'), $parameters);

        return new JsonResponse($this->detail($id), 201);
    }

    #[Route('/tickets/{id}/plan', name: 'api_admin_ticket_plan', methods: ['POST'])]
    public function plan(string $id, Request $request, #[CurrentUser] Account $admin): JsonResponse
    {
        $body = AuthController::json($request);
        $parameters = $body['parameters'] ?? null;
        if (!\is_array($parameters)) {
            throw new BadRequestHttpException('Missing field parameters');
        }
        $this->service->setPlan($admin, $id, AuthController::str($body, 'operation'), $parameters);

        return new JsonResponse($this->detail($id));
    }

    #[Route('/tickets/{id}/retry', name: 'api_admin_ticket_retry', methods: ['POST'])]
    public function retry(string $id, #[CurrentUser] Account $admin): JsonResponse
    {
        $this->service->retry($admin, $id);

        return new JsonResponse($this->detail($id));
    }

    #[Route('/tickets/{id}/reject', name: 'api_admin_ticket_reject', methods: ['POST'])]
    public function reject(string $id, #[CurrentUser] Account $admin): JsonResponse
    {
        $this->service->reject($admin, $id);

        return new JsonResponse($this->detail($id));
    }

    #[Route('/catalog', name: 'api_admin_catalog', methods: ['GET'])]
    public function catalog(IntegrationProfile $profile, BusinessApi $business): JsonResponse
    {
        try {
            $manufacturers = array_values(array_map(
                static fn ($m): array => ['code' => $m->code, 'name' => $m->name],
                array_filter($business->manufacturers(), static fn ($m): bool => $m->active),
            ));
        } catch (DataSourceException) {
            throw new Refusal('data_source_unavailable', 503);
        }

        return new JsonResponse([
            'profile' => $profile->label(),
            'operations' => array_values(array_map(static fn ($op): array => ['id' => $op->id, 'label' => $op->label, 'parameters' => $op->parameters], $profile->operations)),
            'manufacturers' => $manufacturers,
            'categories' => $profile->categories,
        ]);
    }

    #[Route('/accounts/{id}/customers', name: 'api_admin_account_customers', methods: ['GET'])]
    public function customers(string $id, EntityManagerInterface $em, BusinessApi $business): JsonResponse
    {
        $account = Uuid::isValid($id) ? $em->find(Account::class, Uuid::fromString($id)) : null;
        if (!$account instanceof Account || Role::Rep !== $account->role() || null === $account->repCode()) {
            throw new Refusal('not_found', 404);
        }
        try {
            $customers = $business->customersOf($account->repCode());
        } catch (DataSourceException) {
            throw new Refusal('data_source_unavailable', 503);
        }

        return new JsonResponse(['items' => array_map(static fn ($c): array => ['code' => $c->code, 'name' => $c->name, 'active' => $c->active], $customers)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(string $id): array
    {
        $ticket = $this->tickets->find($id) ?? throw new Refusal('not_found', 404);

        return $this->view->detail($ticket, $this->tickets->plans($ticket['id']), $this->tickets->events($ticket['id']));
    }
}
