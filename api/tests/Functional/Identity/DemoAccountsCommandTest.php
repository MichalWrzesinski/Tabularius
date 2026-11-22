<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Command\DemoAccountsCommand;
use App\Module\Identity\AccountProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DemoAccountsCommandTest extends IdentityTestCase
{
    public function testCreatesSharedAccountsIdempotently(): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:accounts:demo'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(200, $this->login('admin@example.com', 'Demo123!'));
        self::assertTrue($this->body()['shared'] ?? null);
        $this->api('POST', '/api/v1/auth/logout');
        self::assertSame(200, $this->login('handlowiec@example.com', 'Demo123!'));
        self::assertSame('PH-01', $this->body()['repCode'] ?? null);
    }

    public function testRefusesOutsideDemoMode(): void
    {
        $c = self::getContainer();
        $command = new DemoAccountsCommand(
            $c->get(EntityManagerInterface::class),
            $c->get(AccountProvider::class),
            $c->get(UserPasswordHasherInterface::class),
            $c->get(ClockInterface::class),
            'production',
            'Demo123!',
        );
        $application = new \Symfony\Component\Console\Application();
        $application->addCommand($command);

        self::assertSame(Command::FAILURE, (new CommandTester($application->find('app:accounts:demo')))->execute([]));
    }
}
