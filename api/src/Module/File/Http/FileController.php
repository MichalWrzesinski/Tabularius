<?php

declare(strict_types=1);

namespace App\Module\File\Http;

use App\Module\Audit\AuditLog;
use App\Module\File\FileAccess;
use App\Module\File\FileStore;
use App\Module\File\ReportFiles;
use App\Module\Identity\Account;
use App\Module\Ticket\Http\TicketView;
use App\Module\Ticket\TicketStore;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Report files behind authorization (ACC-FILE-1): lists for the administrator and for the owner, metadata with a
 * preview, and the download. Every file request runs FileAccess; there is no public file URL.
 *
 * @phpstan-import-type FileRow from ReportFiles
 */
#[Route('/api/v1')]
final class FileController
{
    private const int PREVIEW_ROWS = 200;

    public function __construct(
        private readonly ReportFiles $files,
        private readonly FileAccess $access,
        private readonly FileStore $store,
    ) {
    }

    #[Route('/admin/files', name: 'api_admin_files', methods: ['GET'])]
    public function all(): JsonResponse
    {
        return new JsonResponse(['items' => array_map(self::view(...), $this->files->list())]);
    }

    #[Route('/me/files', name: 'api_my_files', methods: ['GET'])]
    public function mine(#[CurrentUser] Account $me): JsonResponse
    {
        return new JsonResponse(['items' => array_map(self::view(...), $this->files->list($me->id()->toRfc4122()))]);
    }

    /** The owner's open tickets (to show what is still being prepared). */
    #[Route('/me/tickets', name: 'api_my_tickets', methods: ['GET'])]
    public function myTickets(#[CurrentUser] Account $me, TicketStore $tickets, TicketView $view): JsonResponse
    {
        $open = array_filter($tickets->list($me->id()->toRfc4122()), static fn (array $t): bool => !\in_array($t['status'], ['completed', 'rejected'], true));

        return new JsonResponse(['items' => array_values(array_map(static fn (array $t): array => array_intersect_key($view->summary($t), array_flip(['id', 'number', 'receivedAt', 'status', 'operation', 'operationLabel'])), $open))]);
    }

    #[Route('/files/{id}', name: 'api_file', methods: ['GET'])]
    public function show(string $id, #[CurrentUser] Account $viewer): JsonResponse
    {
        $file = $this->access->authorize($viewer, $this->files->find($id));
        [$header, $rows] = self::parse($this->store->get($file['storage_key']), self::PREVIEW_ROWS);

        return new JsonResponse(self::view($file) + ['header' => $header, 'rows' => $rows, 'previewTruncated' => $file['row_count'] > self::PREVIEW_ROWS]);
    }

    #[Route('/files/{id}/content', name: 'api_file_content', methods: ['GET'])]
    public function download(string $id, #[CurrentUser] Account $viewer, AuditLog $audit): Response
    {
        $file = $this->access->authorize($viewer, $this->files->find($id));
        $content = $this->store->get($file['storage_key']);
        $audit->record('account:'.$viewer->id()->toRfc4122(), 'file.download', 'file:'.$file['id']);

        return new Response($content, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file['name']),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param FileRow $f
     *
     * @return array<string, mixed>
     */
    private static function view(array $f): array
    {
        return [
            'id' => $f['id'],
            'ticketId' => $f['ticket_id'],
            'ticketNumber' => 'T-'.$f['ticket_number'],
            'ownerId' => $f['owner_account_id'],
            'ownerName' => $f['owner_name'],
            'name' => $f['name'],
            'operation' => $f['operation'],
            'periodLabel' => $f['period_label'],
            'scopeLabel' => $f['scope_label'],
            'rowCount' => $f['row_count'],
            'empty' => $f['empty'],
            'sizeBytes' => $f['size_bytes'],
            'createdAt' => TicketView::iso($f['created_at']),
            'expiresAt' => TicketView::iso($f['expires_at']),
        ];
    }

    /**
     * Our own CSV back into rows for the preview: `typ_wiersza` becomes meta / detail / total.
     *
     * @return array{0: list<string>, 1: list<array{type: string, cells: list<string>}>}
     */
    private static function parse(string $csv, int $limit): array
    {
        $stream = fopen('php://memory', 'r+');
        \assert(false !== $stream);
        fwrite($stream, str_starts_with($csv, "\u{FEFF}") ? substr($csv, 3) : $csv);
        rewind($stream);
        $header = [];
        $rows = [];
        $details = 0;
        while (false !== ($cells = fgetcsv($stream, null, ';', '"', ''))) {
            $cells = array_map(static fn (?string $c): string => (string) $c, $cells);
            if ([] === $header) {
                $header = $cells;
                continue;
            }
            $type = match ($cells[0]) {
                'meta' => 'meta',
                'pozycja' => 'detail',
                default => 'total',
            };
            if ('detail' === $type && ++$details > $limit) {
                continue;
            }
            $rows[] = ['type' => $type, 'cells' => $cells];
        }
        fclose($stream);

        return [$header, $rows];
    }
}
