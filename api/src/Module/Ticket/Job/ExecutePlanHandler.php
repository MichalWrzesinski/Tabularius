<?php

declare(strict_types=1);

namespace App\Module\Ticket\Job;

use App\Module\File\FileStore;
use App\Module\File\ReportFiles;
use App\Module\Report\Business\DataSourceException;
use App\Module\Report\Business\OutOfScopeException;
use App\Module\Report\Business\ReportTooLargeException;
use App\Module\Report\Csv\CsvWriter;
use App\Module\Report\Execution\InvalidParametersException;
use App\Module\Report\Execution\ReportEngine;
use App\Module\Report\Execution\ReportResult;
use App\Module\Report\Execution\Scope;
use App\Module\Report\Period\DateRange;
use App\Module\Report\Period\PeriodException;
use App\Module\Ticket\TicketStatus;
use App\Module\Ticket\TicketStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Executes one plan version (TKT-VER). Three short steps, each under the ticket's row lock:
 * 1. claim — the job must match the current version, the ticket must be queued (or running after a crash) and have
 *    no file for this version; the owner is re-checked (ACC-LIFE: a blocked owner gets nothing);
 * 2. execute — deterministic report engine with the owner's current scope (outside the transaction);
 * 3. store — the file is kept only if the ticket is still running this version and no file exists for it.
 * A duplicate or stale job is recorded and discarded; business failures become ticket states, never exceptions.
 */
#[AsMessageHandler]
final class ExecutePlanHandler
{
    public function __construct(
        private readonly TicketStore $tickets,
        private readonly ReportFiles $files,
        private readonly FileStore $store,
        private readonly ReportEngine $engine,
        private readonly CsvWriter $csv,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly \App\Module\Notification\Outbox $outbox,
        #[Autowire(env: 'int:REPORT_FILE_TTL_DAYS')]
        private readonly int $ttlDays = 7,
    ) {
    }

    public function __invoke(ExecutePlan $job): void
    {
        $claim = $this->claim($job);
        if (null === $claim) {
            return;
        }

        try {
            $report = $this->engine->run($claim['operation'], $claim['parameters'], new Scope($claim['rep_code']), $claim['reference']);
        } catch (OutOfScopeException) {
            $this->fail($job, 'out_of_scope', 'Zakres klientów pracownika nie obejmuje żądanych danych; raport nie został wygenerowany');

            return;
        } catch (InvalidParametersException|PeriodException|\InvalidArgumentException) {
            $this->fail($job, 'invalid_parameters', 'Parametry planu są nieprawidłowe; potrzebna decyzja administratora');

            return;
        } catch (ReportTooLargeException) {
            $this->fail($job, 'too_large', 'Raport przekracza limit wierszy; zawęź okres lub zakres');

            return;
        } catch (DataSourceException $e) {
            $this->logger->warning('Business system unavailable during execution', ['ticket' => $job->ticketId, 'error' => $e->getMessage()]);
            $this->fail($job, 'data_source_error', 'System źródłowy niedostępny; wykonanie można ponowić');

            return;
        } catch (\Throwable $e) {
            $this->logger->error('Report execution failed', ['ticket' => $job->ticketId, 'exception' => $e]);
            $this->fail($job, 'internal_error', 'Błąd techniczny podczas generowania raportu');

            return;
        }

        $this->keep($job, $claim, $report);
    }

    /**
     * @return array{operation: string, parameters: array<array-key, mixed>, rep_code: string, owner_id: string, reference: \DateTimeImmutable}|null
     */
    private function claim(ExecutePlan $job): ?array
    {
        return $this->tickets->connection()->transactional(function () use ($job): ?array {
            $ticket = $this->tickets->lock($job->ticketId);
            if (null === $ticket) {
                $this->logger->warning('Job for an unknown ticket discarded', ['ticket' => $job->ticketId]);

                return null;
            }
            $now = $this->clock->now();
            $runnable = \in_array($ticket['status'], [TicketStatus::Queued, TicketStatus::Running], true);
            if ($ticket['plan_version'] !== $job->version || !$runnable || $this->files->existsForVersion($job->ticketId, $job->version)) {
                $this->tickets->event($job->ticketId, 'system', 'System', \sprintf('Zadanie dla planu v%d pominięte (plan nieaktualny lub już wykonany)', $job->version), $now);

                return null;
            }
            $plan = $this->tickets->plan($job->ticketId, $job->version);
            $owner = null === $ticket['owner_account_id'] ? false : $this->tickets->connection()->fetchAssociative('SELECT status, role, rep_code FROM account WHERE id = ?', [$ticket['owner_account_id']]);
            if (null === $plan || null === $plan['operation']) {
                throw new \LogicException('Queued ticket without an executable plan');
            }
            if (false === $owner || 'active' !== $owner['status'] || 'rep' !== $owner['role'] || !\is_string($owner['rep_code'])) {
                $status = TicketStatus::Queued === $ticket['status'] ? TicketStatus::Rejected : TicketStatus::NeedsIntervention;
                $this->tickets->setStatus($job->ticketId, $status, 'owner_inactive', $now);
                $this->tickets->event($job->ticketId, 'system', 'System', 'Kontrola uprawnień przed wykonaniem: konto pracownika nieaktywne; raport nie został wygenerowany', $now);

                return null;
            }
            if (TicketStatus::Queued === $ticket['status']) {
                $this->tickets->setStatus($job->ticketId, TicketStatus::Running, null, $now);
                $this->tickets->event($job->ticketId, 'system', 'System', 'Ponowna kontrola uprawnień przed wykonaniem: OK; raport w realizacji', $now);
            }
            $reference = $this->tickets->connection()->fetchOne('SELECT reference_date FROM ticket WHERE id = ?', [$job->ticketId]);

            return [
                'operation' => $plan['operation'],
                'parameters' => $plan['parameters'],
                'rep_code' => $owner['rep_code'],
                'owner_id' => (string) $ticket['owner_account_id'], // checked above: an active owner exists
                'reference' => DateRange::date(\is_string($reference) ? $reference : ''),
            ];
        });
    }

    /**
     * @param array{operation: string, parameters: array<array-key, mixed>, rep_code: string, owner_id: string, reference: \DateTimeImmutable} $claim
     */
    private function keep(ExecutePlan $job, array $claim, ReportResult $report): void
    {
        $content = $this->csv->write($report);
        $key = bin2hex(random_bytes(16));
        $this->store->put($key, $content);

        $kept = false;
        try {
            $kept = $this->tickets->connection()->transactional(function () use ($job, $claim, $report, $content, $key): bool {
                $ticket = $this->tickets->lock($job->ticketId);
                $now = $this->clock->now();
                if (null === $ticket || $ticket['plan_version'] !== $job->version || TicketStatus::Running !== $ticket['status'] || $this->files->existsForVersion($job->ticketId, $job->version)) {
                    $this->tickets->event($job->ticketId, 'system', 'System', \sprintf('Wynik planu v%d odrzucony (zgłoszenie zmienione w trakcie lub plik już istnieje)', $job->version), $now);

                    return false;
                }
                $fileId = Uuid::v7()->toRfc4122();
                $this->files->insert([
                    'id' => $fileId,
                    'ticket_id' => $job->ticketId,
                    'plan_version' => $job->version,
                    'owner_account_id' => $claim['owner_id'],
                    'rep_code' => $claim['rep_code'],
                    'customer_codes' => $report->customers,
                    'operation' => $report->operation,
                    'name' => $report->fileName,
                    'period_label' => $report->periodLabel,
                    'scope_label' => $report->scopeLabel,
                    'row_count' => \count(array_filter($report->rows, static fn ($r): bool => 'pozycja' === $r->type)),
                    'empty' => $report->empty,
                    'size_bytes' => \strlen($content),
                    'sha256' => hash('sha256', $content),
                    'storage_key' => $key,
                    'created_at' => TicketStore::ts($now),
                    'expires_at' => TicketStore::ts($now->modify(\sprintf('+%d days', $this->ttlDays))),
                ]);
                $this->tickets->setStatus($job->ticketId, TicketStatus::Completed, null, $now);
                $this->outbox->enqueue($job->ticketId, 'report_ready', $job->ticketId.':ready:'.$job->version, ['fileId' => $fileId]);
                $this->tickets->event($job->ticketId, 'system', 'System', \sprintf('Raport wygenerowany: %s%s', $report->fileName, $report->empty ? ' (brak danych w okresie)' : ''), $now);

                return true;
            });
        } finally {
            if (!$kept) {
                $this->store->delete($key);
            }
        }
    }

    private function fail(ExecutePlan $job, string $reason, string $text): void
    {
        $this->tickets->connection()->transactional(function () use ($job, $reason, $text): void {
            $ticket = $this->tickets->lock($job->ticketId);
            if (null === $ticket || $ticket['plan_version'] !== $job->version || TicketStatus::Running !== $ticket['status']) {
                return;
            }
            $now = $this->clock->now();
            $this->tickets->setStatus($job->ticketId, TicketStatus::NeedsIntervention, $reason, $now);
            $this->tickets->event($job->ticketId, 'system', 'System', $text, $now);
        });
    }
}
