<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Append-only audit of security events and human changes. References are pseudonymous (account UUIDs, ticket
 * numbers) and details carry codes only: never message bodies, report rows or passwords (architecture.md).
 */
final class AuditLog
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string|int|bool> $details
     */
    public function record(string $actorRef, string $action, string $targetRef, array $details = []): void
    {
        $this->connection->insert('audit_event', [
            'at' => $this->clock->now()->format('Y-m-d H:i:s.uP'),
            'actor_ref' => $actorRef,
            'action' => $action,
            'target_ref' => $targetRef,
            'details' => json_encode($details, \JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return list<array{at: string, actorRef: string, action: string, targetRef: string}>
     */
    public function latest(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT at, actor_ref, action, target_ref FROM audit_event ORDER BY id DESC LIMIT ?', [$limit], [\Doctrine\DBAL\ParameterType::INTEGER]);

        return array_map(static fn (array $r): array => [
            'at' => (new \DateTimeImmutable(\is_string($r['at']) ? $r['at'] : 'now'))->format(\DATE_ATOM),
            'actorRef' => \is_string($r['actor_ref']) ? $r['actor_ref'] : '',
            'action' => \is_string($r['action']) ? $r['action'] : '',
            'targetRef' => \is_string($r['target_ref']) ? $r['target_ref'] : '',
        ], $rows);
    }
}
