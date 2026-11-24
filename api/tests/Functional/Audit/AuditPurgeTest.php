<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Command\AuditPurgeCommand;
use App\Module\Audit\AuditLog;
use App\Tests\Functional\Identity\IdentityTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/** TASK-016: the pseudonymous audit log is kept 90 days (ID-DEMO-7). */
final class AuditPurgeTest extends IdentityTestCase
{
    public function testOnlyEventsOlderThan90DaysAreDeleted(): void
    {
        $db = self::getContainer()->get(Connection::class);
        foreach (['2026-08-16 09:00:00+00', '2026-08-18 09:00:00+00', '2026-11-15 09:00:00+00'] as $at) {
            $db->insert('audit_event', ['at' => $at, 'actor_ref' => 'system', 'action' => 'test', 'target_ref' => 'ticket:x', 'details' => '{}']);
        }
        $command = new AuditPurgeCommand(self::getContainer()->get(AuditLog::class), new MockClock('2026-11-15 10:00:00+00:00'));
        $output = new BufferedOutput();

        self::assertSame(0, $command(new SymfonyStyle(new ArrayInput([]), $output)));
        self::assertStringContainsString('Deleted 1 audit event', $output->fetch());
        self::assertSame(['2026-08-18', '2026-11-15'], $db->fetchFirstColumn("SELECT to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD') FROM audit_event ORDER BY at"));
    }
}
