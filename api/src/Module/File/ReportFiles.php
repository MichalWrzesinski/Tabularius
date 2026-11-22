<?php

declare(strict_types=1);

namespace App\Module\File;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Report file metadata. One row per (ticket, plan version); the unique constraint is the idempotency guarantee.
 *
 * @phpstan-type FileRow array{id: string, ticket_id: string, ticket_number: int, plan_version: int, owner_account_id: string, owner_name: string, rep_code: string, customer_codes: list<string>, operation: string, name: string, period_label: string, scope_label: string, row_count: int, empty: bool, size_bytes: int, sha256: string, storage_key: string, created_at: string, expires_at: string, deleted_at: ?string}
 */
final class ReportFiles
{
    private const string SELECT = <<<'SQL'
        SELECT f.*, t.number AS ticket_number, a.name AS owner_name
        FROM report_file f
        JOIN ticket t ON t.id = f.ticket_id
        JOIN account a ON a.id = f.owner_account_id
        SQL;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{id: string, ticket_id: string, plan_version: int, owner_account_id: string, rep_code: string, customer_codes: list<string>, operation: string, name: string, period_label: string, scope_label: string, row_count: int, empty: bool, size_bytes: int, sha256: string, storage_key: string, created_at: string, expires_at: string} $file
     *
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException a file for this plan version already exists
     */
    public function insert(array $file): void
    {
        $this->db->insert('report_file', ['customer_codes' => json_encode($file['customer_codes'], \JSON_THROW_ON_ERROR)] + $file, ['empty' => ParameterType::BOOLEAN]);
    }

    public function existsForVersion(string $ticketId, int $version): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM report_file WHERE ticket_id = ? AND plan_version = ?', [$ticketId, $version]);
    }

    /**
     * @return FileRow|null
     */
    public function find(string $id): ?array
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return null;
        }
        $row = $this->db->fetchAssociative(self::SELECT.' WHERE f.id = ?', [$id]);

        return false === $row ? null : self::row($row);
    }

    /**
     * Files not yet deleted, newest first; optionally only one owner's.
     *
     * @return list<FileRow>
     */
    public function list(?string $ownerId = null, int $limit = 200): array
    {
        $rows = null === $ownerId
            ? $this->db->fetchAllAssociative(self::SELECT.' WHERE f.deleted_at IS NULL ORDER BY f.created_at DESC LIMIT ?', [$limit], [ParameterType::INTEGER])
            : $this->db->fetchAllAssociative(self::SELECT.' WHERE f.deleted_at IS NULL AND f.owner_account_id = ? ORDER BY f.created_at DESC LIMIT ?', [$ownerId, $limit], [ParameterType::STRING, ParameterType::INTEGER]);

        return array_map(self::row(...), $rows);
    }

    /**
     * @return list<array{id: string, storage_key: string}>
     */
    public function expired(\DateTimeImmutable $now): array
    {
        return array_map(
            static fn (array $r): array => ['id' => self::str($r['id']), 'storage_key' => self::str($r['storage_key'])],
            $this->db->fetchAllAssociative('SELECT id, storage_key FROM report_file WHERE deleted_at IS NULL AND expires_at <= ?', [$now->format('Y-m-d H:i:s.uP')]),
        );
    }

    public function markDeleted(string $id, \DateTimeImmutable $now): void
    {
        $this->db->update('report_file', ['deleted_at' => $now->format('Y-m-d H:i:s.uP')], ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return FileRow
     */
    private static function row(array $r): array
    {
        $codes = json_decode(self::str($r['customer_codes']), true, 4, \JSON_THROW_ON_ERROR);

        return [
            'id' => self::str($r['id']),
            'ticket_id' => self::str($r['ticket_id']),
            'ticket_number' => (int) self::str($r['ticket_number']),
            'plan_version' => (int) self::str($r['plan_version']),
            'owner_account_id' => self::str($r['owner_account_id']),
            'owner_name' => self::str($r['owner_name']),
            'rep_code' => self::str($r['rep_code']),
            'customer_codes' => \is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [],
            'operation' => self::str($r['operation']),
            'name' => self::str($r['name']),
            'period_label' => self::str($r['period_label']),
            'scope_label' => self::str($r['scope_label']),
            'row_count' => (int) self::str($r['row_count']),
            'empty' => true === $r['empty'],
            'size_bytes' => (int) self::str($r['size_bytes']),
            'sha256' => self::str($r['sha256']),
            'storage_key' => self::str($r['storage_key']),
            'created_at' => self::str($r['created_at']),
            'expires_at' => self::str($r['expires_at']),
            'deleted_at' => null === $r['deleted_at'] ? null : self::str($r['deleted_at']),
        ];
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \UnexpectedValueException('Unexpected database value');
    }
}
