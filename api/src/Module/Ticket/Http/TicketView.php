<?php

declare(strict_types=1);

namespace App\Module\Ticket\Http;

use App\Module\Report\Period\DateRange;
use App\Module\Report\Period\PeriodExpression;
use App\Module\Report\Period\PeriodResolver;
use App\Module\Report\Profile\IntegrationProfile;
use App\Module\Ticket\TicketStore;

/**
 * JSON views of tickets. Plan parameters are shown resolved (concrete dates, DATE-5) with Polish labels; texts come
 * from backend templates, never from user or model content.
 *
 * @phpstan-import-type TicketRow from TicketStore
 * @phpstan-import-type PlanRow from TicketStore
 * @phpstan-import-type EventRow from TicketStore
 */
final class TicketView
{
    public function __construct(
        private readonly PeriodResolver $periods,
        private readonly IntegrationProfile $profile,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'APP_MODE')]
        private readonly string $mode = 'demo',
    ) {
    }

    /**
     * @param TicketRow $t
     *
     * @return array<string, mixed>
     */
    public function summary(array $t): array
    {
        return [
            'id' => $t['id'],
            'number' => 'T-'.$t['number'],
            'ownerId' => $t['owner_account_id'],
            ...$this->person($t),
            'source' => $t['source'],
            'receivedAt' => self::iso($t['received_at']),
            'updatedAt' => self::iso($t['updated_at']),
            'status' => $t['status'],
            'reason' => $t['reason'],
            'operation' => $t['operation'],
            'operationLabel' => null === $t['operation'] ? null : ($this->profile->operations[$t['operation']]->label ?? $t['operation']),
            'planVersion' => $t['plan_version'],
            'fileId' => $t['file_id'],
            // The current plan only; details carry every version.
            'plans' => null === $t['plan'] ? [] : [$this->plan($t['plan'], DateRange::date($t['reference_date']))],
        ];
    }

    /**
     * Who the ticket is shown as. Demo mail tickets show only the synthetic alias, never the shared account or any
     * address (ID-DEMO-3); production shows the owner, and nothing for an unverified sender (ID-4).
     *
     * @param TicketRow $t
     *
     * @return array{employeeName: string, employeeEmail: string, participantAlias: ?string}
     */
    private function person(array $t): array
    {
        if ('mail' === $t['source'] && 'demo' === $this->mode) {
            return ['employeeName' => $t['participant_alias'] ?? 'Uczestnik demo', 'employeeEmail' => '', 'participantAlias' => $t['participant_alias']];
        }

        return ['employeeName' => $t['owner_name'] ?? 'Nadawca nierozpoznany', 'employeeEmail' => $t['owner_email'] ?? '', 'participantAlias' => null];
    }

    /**
     * @param TicketRow      $t
     * @param list<PlanRow>  $plans
     * @param list<EventRow> $events
     *
     * @return array<string, mixed>
     */
    public function detail(array $t, array $plans, array $events): array
    {
        $reference = DateRange::date($t['reference_date']);

        return [
            ...$this->summary($t),
            'referenceDate' => $t['reference_date'],
            'plans' => array_map(fn (array $p): array => $this->plan($p, $reference), $plans),
            'events' => array_map(static fn (array $e): array => [
                'at' => self::iso($e['at']),
                'actor' => $e['actor'],
                'actorName' => $e['actor_name'],
                'text' => $e['text'],
            ], $events),
            'messages' => [],
            'clarificationRounds' => 0,
        ];
    }

    /**
     * @param PlanRow $p
     *
     * @return array<string, mixed>
     */
    private function plan(array $p, \DateTimeImmutable $reference): array
    {
        return [
            'version' => $p['version'],
            'author' => $p['author'],
            'at' => self::iso($p['created_at']),
            'operation' => $p['operation'],
            'params' => $this->params($p, $reference),
            'issues' => $p['issues'],
        ];
    }

    /**
     * @param PlanRow $plan
     *
     * @return list<array{label: string, value: string}>
     */
    private function params(array $plan, \DateTimeImmutable $reference): array
    {
        $p = $plan['parameters'];
        $out = [];
        if (\is_array($p['customer'] ?? null) || \is_string($p['customer'] ?? null)) {
            $out[] = ['label' => 'Klient', 'value' => \is_string($p['customer']) ? $p['customer'] : '—'];
        }
        if (\array_key_exists('period', $p)) {
            $out[] = ['label' => 'Okres', 'value' => $this->period($p['period'], $reference)];
        }
        if ('sales_report' === $plan['operation']) {
            $customers = $p['customers'] ?? 'all_assigned';
            $out[] = ['label' => 'Zakres', 'value' => 'all_assigned' === $customers ? 'Przypisani klienci' : (\is_array($customers) ? implode(', ', array_filter($customers, 'is_string')) : '—')];
        }
        if (\is_string($p['manufacturer'] ?? null)) {
            $out[] = ['label' => 'Producent', 'value' => $p['manufacturer']];
        }
        if (\is_string($p['category'] ?? null)) {
            $out[] = ['label' => 'Kategoria', 'value' => $p['category']];
        }
        if ('customer_discount_simulation' === $plan['operation']) {
            $out[] = ['label' => 'Polityka', 'value' => \is_string($p['policy'] ?? null) ? 'DEMO-RABAT '.$p['policy'] : 'Obowiązująca w dniu zgłoszenia (fikcyjna)'];
        }

        return $out;
    }

    private function period(mixed $expression, \DateTimeImmutable $reference): string
    {
        if (!\is_array($expression)) {
            return 'brak';
        }
        try {
            return $this->periods->resolve(PeriodExpression::fromArray($expression), $reference)->label();
        } catch (\RuntimeException|\InvalidArgumentException) {
            return 'nieprawidłowy okres';
        }
    }

    public static function iso(string $timestamp): string
    {
        return new \DateTimeImmutable($timestamp)->format(\DATE_ATOM);
    }
}
