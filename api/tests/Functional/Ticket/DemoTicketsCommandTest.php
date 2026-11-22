<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticket;

use App\Module\Identity\Role;
use App\Tests\Functional\Identity\IdentityTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DemoTicketsCommandTest extends IdentityTestCase
{
    public function testQueuesSampleTicketsOnceAndNeedsTheSharedAccounts(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find('app:tickets:demo'));
        self::assertSame(1, $tester->execute([]));

        $this->account('admin@example.com', Role::Admin, shared: true);
        $this->account('handlowiec@example.com', rep: 'PH-01', shared: true);
        self::assertSame(0, $tester->execute([]));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('nothing queued', $tester->getDisplay());

        $db = self::getContainer()->get(Connection::class);
        self::assertSame(['sales_report', 'parts_catalog', 'customer_discount_simulation'], $db->fetchFirstColumn('SELECT p.operation FROM ticket t JOIN ticket_plan p ON p.ticket_id = t.id ORDER BY t.number'));
        self::assertEquals(3, $db->fetchOne("SELECT count(*) FROM ticket WHERE status = 'queued'"));
    }
}
