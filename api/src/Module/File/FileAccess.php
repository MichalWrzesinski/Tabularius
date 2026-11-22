<?php

declare(strict_types=1);

namespace App\Module\File;

use App\Module\Identity\Account;
use App\Module\Identity\Role;
use App\Module\Report\Business\BusinessApi;
use App\Module\Report\Business\DataSourceException;
use App\Module\Shared\Refusal;
use Psr\Clock\ClockInterface;

/**
 * ACC-FILE-1: a file is available to its owner while its scope is still within the owner's current scope and it has
 * not expired, and to the administrator while it has not expired. Checked on every request (metadata, preview and
 * download); someone else's file is indistinguishable from a missing one. Fails closed when the scope cannot be read.
 *
 * @phpstan-import-type FileRow from ReportFiles
 */
final class FileAccess
{
    public function __construct(
        private readonly BusinessApi $business,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param FileRow|null $file
     *
     * @return FileRow
     */
    public function authorize(Account $viewer, ?array $file): array
    {
        if (null === $file) {
            throw new Refusal('not_found', 404);
        }
        $admin = Role::Admin === $viewer->role();
        if (!$admin && $file['owner_account_id'] !== $viewer->id()->toRfc4122()) {
            throw new Refusal('not_found', 404);
        }
        if (null !== $file['deleted_at'] || new \DateTimeImmutable($file['expires_at']) <= $this->clock->now()) {
            throw new Refusal('file_expired', 410);
        }
        if ($admin) {
            return $file;
        }
        if ($viewer->repCode() !== $file['rep_code']) {
            throw new Refusal('scope_changed', 403);
        }
        if ([] !== $file['customer_codes']) {
            try {
                $current = array_map(static fn ($c): string => $c->code, $this->business->customersOf($file['rep_code']));
            } catch (DataSourceException) {
                throw new Refusal('scope_unavailable', 503);
            }
            if ([] !== array_diff($file['customer_codes'], $current)) {
                throw new Refusal('scope_changed', 403);
            }
        }

        return $file;
    }
}
