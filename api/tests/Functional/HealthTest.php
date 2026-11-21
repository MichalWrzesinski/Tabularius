<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthAnswersWithoutDependencies(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertSame('{"status":"ok"}', $client->getResponse()->getContent());
    }

    public function testReadyChecksTheDatabase(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/ready');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ok","checks":{"database":"up"}}', (string) $client->getResponse()->getContent());
    }

    public function testReadyReportsUnavailableDatabaseWithout500(): void
    {
        $client = self::createClient();
        $broken = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => 'no-such-host.invalid', 'dbname' => 'x', 'user' => 'x', 'password' => 'x']);
        self::getContainer()->set('doctrine.dbal.default_connection', $broken);

        $client->request('GET', '/api/ready');

        self::assertResponseStatusCodeSame(503);
        self::assertJsonStringEqualsJsonString('{"status":"unavailable","checks":{"database":"down"}}', (string) $client->getResponse()->getContent());
    }
}
