<?php

declare(strict_types=1);

namespace App\Module\Ticket\Http;

use App\Module\Identity\Account;
use App\Module\Shared\Refusal;
use App\Module\Ticket\TicketService;
use App\Module\Ticket\TicketStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The representative's own tickets: the confirmation screen (ID-2). Someone else's ticket is indistinguishable from
 * a missing one. Shows the recognized operation and resolved parameters, never message contents or history.
 */
#[Route('/api/v1/me/tickets')]
final class MyTicketController
{
    public function __construct(
        private readonly TicketStore $tickets,
        private readonly TicketService $service,
        private readonly TicketView $view,
    ) {
    }

    #[Route('/{id}', name: 'api_my_ticket', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(string $id, #[CurrentUser] Account $me): JsonResponse
    {
        return new JsonResponse($this->own($id, $me));
    }

    #[Route('/{id}/confirm', name: 'api_my_ticket_confirm', methods: ['POST'])]
    public function confirm(string $id, #[CurrentUser] Account $me): JsonResponse
    {
        $this->service->confirm($me, $id);

        return new JsonResponse($this->own($id, $me));
    }

    #[Route('/{id}/cancel', name: 'api_my_ticket_cancel', methods: ['POST'])]
    public function cancel(string $id, #[CurrentUser] Account $me): JsonResponse
    {
        $this->service->cancel($me, $id);

        return new JsonResponse($this->own($id, $me));
    }

    /**
     * @return array<string, mixed>
     */
    private function own(string $id, Account $me): array
    {
        $ticket = $this->tickets->find($id);
        if (null === $ticket || $ticket['owner_account_id'] !== $me->id()->toRfc4122()) {
            throw new Refusal('not_found', 404);
        }
        $summary = $this->view->summary($ticket);

        return array_intersect_key($summary, array_flip(['id', 'number', 'receivedAt', 'status', 'reason', 'operation', 'operationLabel', 'fileId', 'plans']));
    }
}
