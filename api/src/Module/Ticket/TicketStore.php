<?php

declare(strict_types=1);

namespace App\Module\Ticket;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Persistence of tickets, plan versions and ticket events (DBAL; jsonb and row locks are explicit).
 *
 * @phpstan-type TicketRow array{id: string, number: int, owner_account_id: string, owner_name: string, owner_email: string, owner_rep_code: ?string, owner_status: string, source: string, status: string, reason: ?string, plan_version: int, reference_date: string, received_at: string, updated_at: string, operation: ?string, file_id: ?string, plan: ?PlanRow}
 * @phpstan-type PlanRow array{version: int, author: string, author_ref: string, created_at: string, operation: ?string, parameters: array<array-key, mixed>, issues: list<string>}
 * @phpstan-type EventRow array{at: string, actor: string, actor_name: string, text: string}
 */
final class TicketStore
{
    private const string SELECT = <<<'SQL'
        SELECT t.id, t.number, t.owner_account_id, a.name AS owner_name, a.email AS owner_email, a.rep_code AS owner_rep_code,
               a.status AS owner_status, t.source, t.status, t.reason, t.plan_version, t.reference_date, t.received_at, t.updated_at,
               p.operation, p.version AS p_version, p.author AS p_author, p.author_ref AS p_author_ref, p.created_at AS p_created_at,
               p.parameters AS p_parameters, p.issues AS p_issues,
               (SELECT f.id FROM report_file f WHERE f.ticket_id = t.id AND f.deleted_at IS NULL ORDER BY f.created_at DESC LIMIT 1) AS file_id
        FROM ticket t
        JOIN account a ON a.id = t.owner_account_id
        LEFT JOIN ticket_plan p ON p.ticket_id = t.id AND p.version = t.plan_version
        SQL;

    public function __construct(private readonly Connection $db)
    {
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    public function insert(Uuid $id, string $ownerId, string $source, TicketStatus $status, \DateTimeImmutable $reference, \DateTimeImmutable $now, string $createdBy): void
    {
        $this->db->insert('ticket', [
            'id' => $id->toRfc4122(),
            'owner_account_id' => $ownerId,
            'source' => $source,
            'status' => $status->value,
            'reference_date' => $reference->format('Y-m-d'),
            'received_at' => self::ts($now),
            'updated_at' => self::ts($now),
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @param list<string>            $issues
     */
    public function addPlan(string $ticketId, int $version, string $author, string $authorRef, \DateTimeImmutable $now, ?string $operation, array $parameters, array $issues = []): void
    {
        $this->db->insert('ticket_plan', [
            'ticket_id' => $ticketId,
            'version' => $version,
            'author' => $author,
            'author_ref' => $authorRef,
            'created_at' => self::ts($now),
            'operation' => $operation,
            'parameters' => json_encode((object) $parameters, \JSON_THROW_ON_ERROR),
            'issues' => json_encode($issues, \JSON_THROW_ON_ERROR),
        ]);
        $this->db->update('ticket', ['plan_version' => $version, 'updated_at' => self::ts($now)], ['id' => $ticketId]);
    }

    public function setStatus(string $ticketId, TicketStatus $status, ?string $reason, \DateTimeImmutable $now): void
    {
        $this->db->update('ticket', ['status' => $status->value, 'reason' => $reason, 'updated_at' => self::ts($now)], ['id' => $ticketId]);
    }

    public function event(string $ticketId, string $actor, string $actorName, string $text, \DateTimeImmutable $now): void
    {
        $this->db->insert('ticket_event', ['ticket_id' => $ticketId, 'at' => self::ts($now), 'actor' => $actor, 'actor_name' => $actorName, 'text' => $text]);
    }

    /**
     * Row lock for the rest of the current transaction (one writer per ticket).
     *
     * @return array{status: TicketStatus, plan_version: int, owner_account_id: string}|null
     */
    public function lock(string $ticketId): ?array
    {
        if (!Uuid::isValid($ticketId)) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT status, plan_version, owner_account_id FROM ticket WHERE id = ? FOR UPDATE', [$ticketId]);
        if (false === $row) {
            return null;
        }

        return [
            'status' => TicketStatus::from(self::str($row['status'])),
            'plan_version' => (int) self::str($row['plan_version']),
            'owner_account_id' => self::str($row['owner_account_id']),
        ];
    }

    /**
     * @return TicketRow|null
     */
    public function find(string $ticketId): ?array
    {
        if (!Uuid::isValid($ticketId)) {
            return null;
        }
        $row = $this->db->fetchAssociative(self::SELECT.' WHERE t.id = ?', [$ticketId]);

        return false === $row ? null : self::ticket($row);
    }

    /**
     * @return list<TicketRow>
     */
    public function list(?string $ownerId = null, int $limit = 200): array
    {
        $rows = null === $ownerId
            ? $this->db->fetchAllAssociative(self::SELECT.' ORDER BY t.received_at DESC LIMIT ?', [$limit], [\Doctrine\DBAL\ParameterType::INTEGER])
            : $this->db->fetchAllAssociative(self::SELECT.' WHERE t.owner_account_id = ? ORDER BY t.received_at DESC LIMIT ?', [$ownerId, $limit], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER]);

        return array_map(self::ticket(...), $rows);
    }

    /**
     * @return PlanRow|null
     */
    public function plan(string $ticketId, int $version): ?array
    {
        $row = $this->db->fetchAssociative('SELECT version, author, author_ref, created_at, operation, parameters, issues FROM ticket_plan WHERE ticket_id = ? AND version = ?', [$ticketId, $version]);

        return false === $row ? null : self::planRow($row);
    }

    /**
     * @return list<PlanRow>
     */
    public function plans(string $ticketId): array
    {
        return array_map(self::planRow(...), $this->db->fetchAllAssociative('SELECT version, author, author_ref, created_at, operation, parameters, issues FROM ticket_plan WHERE ticket_id = ? ORDER BY version', [$ticketId]));
    }

    /**
     * @return list<EventRow>
     */
    public function events(string $ticketId): array
    {
        return array_map(static fn (array $r): array => [
            'at' => self::str($r['at']),
            'actor' => self::str($r['actor']),
            'actor_name' => self::str($r['actor_name']),
            'text' => self::str($r['text']),
        ], $this->db->fetchAllAssociative('SELECT at, actor, actor_name, text FROM ticket_event WHERE ticket_id = ? ORDER BY id', [$ticketId]));
    }

    public static function ts(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.uP');
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return TicketRow
     */
    private static function ticket(array $r): array
    {
        return [
            'id' => self::str($r['id']),
            'number' => (int) self::str($r['number']),
            'owner_account_id' => self::str($r['owner_account_id']),
            'owner_name' => self::str($r['owner_name']),
            'owner_email' => self::str($r['owner_email']),
            'owner_rep_code' => null === $r['owner_rep_code'] ? null : self::str($r['owner_rep_code']),
            'owner_status' => self::str($r['owner_status']),
            'source' => self::str($r['source']),
            'status' => self::str($r['status']),
            'reason' => null === $r['reason'] ? null : self::str($r['reason']),
            'plan_version' => (int) self::str($r['plan_version']),
            'reference_date' => self::str($r['reference_date']),
            'received_at' => self::str($r['received_at']),
            'updated_at' => self::str($r['updated_at']),
            'operation' => null === $r['operation'] ? null : self::str($r['operation']),
            'file_id' => null === $r['file_id'] ? null : self::str($r['file_id']),
            'plan' => null === $r['p_version'] ? null : self::planRow([
                'version' => $r['p_version'], 'author' => $r['p_author'], 'author_ref' => $r['p_author_ref'], 'created_at' => $r['p_created_at'],
                'operation' => $r['operation'], 'parameters' => $r['p_parameters'], 'issues' => $r['p_issues'],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return PlanRow
     */
    private static function planRow(array $r): array
    {
        $parameters = json_decode(self::str($r['parameters']), true, 32, \JSON_THROW_ON_ERROR);
        $issues = json_decode(self::str($r['issues']), true, 4, \JSON_THROW_ON_ERROR);

        return [
            'version' => (int) self::str($r['version']),
            'author' => self::str($r['author']),
            'author_ref' => self::str($r['author_ref']),
            'created_at' => self::str($r['created_at']),
            'operation' => null === $r['operation'] ? null : self::str($r['operation']),
            'parameters' => \is_array($parameters) ? $parameters : [],
            'issues' => \is_array($issues) ? array_values(array_filter($issues, 'is_string')) : [],
        ];
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \UnexpectedValueException('Unexpected database value');
    }
}
