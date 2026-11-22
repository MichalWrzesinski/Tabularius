<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Module\Identity\Account;
use App\Module\Identity\Role;
use App\Tests\Double\StubBusinessApi;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class IdentityTestCase extends WebTestCase
{
    protected const string PASSWORD = 'Secret-pass-123'; // gitleaks:allow (test-only value)
    protected const string NEW_PASSWORD = 'Another-pass-456'; // gitleaks:allow (test-only value)

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $db = self::getContainer()->get(Connection::class);
        foreach (['messenger_messages', 'ai_usage', 'outbox', 'mail_message', 'report_file', 'ticket_event', 'ticket_plan', 'ticket', 'account_session', 'sessions', 'audit_event', 'account'] as $table) {
            $db->executeStatement("DELETE FROM $table");
        }
        self::getContainer()->get('cache.rate_limiter')->clear();
        StubBusinessApi::reset();
    }

    protected function account(string $email, Role $role = Role::Rep, ?string $rep = 'PH-02', string $password = self::PASSWORD, bool $shared = false): Account
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $account = new Account($email, 'Test '.$email, $role, Role::Rep === $role ? $rep : null, 'x', new \DateTimeImmutable(), $shared);
        $account->changePassword($hasher->hashPassword($account, $password), new \DateTimeImmutable());
        $em->persist($account);
        $em->flush();

        return $account;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function api(string $method, string $uri, ?array $body = null, bool $csrf = true): int
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($csrf) {
            $server['HTTP_X_TABULARIUS_REQUEST'] = '1';
        }
        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse()->getStatusCode();
    }

    protected function login(string $email, string $password = self::PASSWORD): int
    {
        return $this->api('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function body(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    protected function assertProblemCode(string $code): void
    {
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame($code, $this->body()['code'] ?? null);
    }
}
