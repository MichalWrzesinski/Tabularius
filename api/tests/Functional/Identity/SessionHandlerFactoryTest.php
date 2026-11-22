<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Module\Identity\Http\SessionHandlerFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sessions use their own PDO connection to the same database (ADR 0003): write, read, destroy.
 */
final class SessionHandlerFactoryTest extends KernelTestCase
{
    public function testStoresSessionsInTheSessionsTable(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $handler = SessionHandlerFactory::create($connection);

        $handler->open('', 'tabularius_session');
        self::assertTrue($handler->write('factory-test', 'payload'));
        $handler->close();
        self::assertEquals(1, $connection->fetchOne("SELECT count(*) FROM sessions WHERE sess_id = 'factory-test'"));

        $handler->open('', 'tabularius_session');
        self::assertSame('payload', $handler->read('factory-test'));
        self::assertTrue($handler->destroy('factory-test'));
        $handler->close();
        self::assertEquals(0, $connection->fetchOne("SELECT count(*) FROM sessions WHERE sess_id = 'factory-test'"));
    }
}
