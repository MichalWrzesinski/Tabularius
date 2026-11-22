<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Module\Identity\Account;
use App\Module\Identity\Role;
use App\Module\Identity\SessionRevoker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CP-004 and ACC-LIFE at API level.
 */
final class AdminAccountsTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->account('admin@example.com', Role::Admin, null, 'Demo123!', shared: true);
        $this->login('admin@example.com', 'Demo123!');
    }

    public function testCreatesARepresentativeAndEmailsAGeneratedPassword(): void
    {
        self::assertSame(201, $this->api('POST', '/api/v1/admin/accounts', ['name' => 'Piotr Nowak', 'email' => 'Piotr.Nowak@example.com', 'repCode' => 'PH-02']));
        $created = $this->body();
        self::assertSame(['name' => 'Piotr Nowak', 'email' => 'piotr.nowak@example.com', 'role' => 'rep', 'repCode' => 'PH-02', 'status' => 'active'], array_intersect_key($created, array_flip(['name', 'email', 'role', 'repCode', 'status'])));
        self::assertStringNotContainsStringIgnoringCase('password', (string) json_encode($created));

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'piotr.nowak@example.com');
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $mail);
        self::assertSame(1, preg_match('/Hasło: ([A-Za-z0-9]{16})/', (string) $mail->getTextBody(), $m));
        $password = $m[1];

        $db = self::getContainer()->get(Connection::class);
        self::assertEquals(0, $db->fetchOne('SELECT count(*) FROM audit_event WHERE details::text LIKE ?', ['%'.$password.'%']));
        self::assertSame('account.created', $db->fetchOne('SELECT action FROM audit_event ORDER BY id DESC LIMIT 1'));

        $this->api('POST', '/api/v1/auth/logout');
        self::assertSame(200, $this->login('piotr.nowak@example.com', $password));
    }

    /**
     * @return iterable<string, array{array<string, string>, int, string}>
     */
    public static function invalidCreations(): iterable
    {
        yield 'unknown rep' => [['name' => 'X', 'email' => 'x@example.com', 'repCode' => 'PH-99'], 422, 'unknown_sales_rep'];
        yield 'bad email' => [['name' => 'X', 'email' => 'not-an-email', 'repCode' => 'PH-03'], 422, 'invalid_email'];
        yield 'empty name' => [['name' => ' ', 'email' => 'x@example.com', 'repCode' => 'PH-03'], 422, 'invalid_name'];
        yield 'taken email' => [['name' => 'X', 'email' => 'ADMIN@example.com', 'repCode' => 'PH-03'], 409, 'email_taken'];
    }

    /**
     * @param array<string, string> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCreations')]
    public function testRejectsInvalidCreations(array $body, int $status, string $code): void
    {
        self::assertSame($status, $this->api('POST', '/api/v1/admin/accounts', $body));
        $this->assertProblemCode($code);
        self::assertEmailCount(0);
    }

    public function testOneAccountPerRepresentative(): void
    {
        $this->account('existing@example.com', Role::Rep, 'PH-03');

        self::assertSame(409, $this->api('POST', '/api/v1/admin/accounts', ['name' => 'X', 'email' => 'x@example.com', 'repCode' => 'PH-03']));
        $this->assertProblemCode('sales_rep_taken');
    }

    public function testBlockingRevokesSessionsAndAccess(): void
    {
        $rep = $this->account('rep@example.com');
        self::getContainer()->get(SessionRevoker::class)->remember('session-of-rep', $rep);
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("INSERT INTO sessions VALUES ('session-of-rep', 'x', 3600, 0)");

        self::assertSame(200, $this->api('POST', '/api/v1/admin/accounts/'.$rep->id()->toRfc4122().'/block'));
        self::assertSame('blocked', $this->body()['status'] ?? null);
        self::assertEquals(0, $db->fetchOne("SELECT count(*) FROM sessions WHERE sess_id = 'session-of-rep'"));

        $this->api('POST', '/api/v1/auth/logout');
        self::assertSame(401, $this->login('rep@example.com'));

        $this->login('admin@example.com', 'Demo123!');
        self::assertSame(200, $this->api('POST', '/api/v1/admin/accounts/'.$rep->id()->toRfc4122().'/unblock'));
        $this->api('POST', '/api/v1/auth/logout');
        self::assertSame(200, $this->login('rep@example.com'));
    }

    public function testBlockedUserLosesTheSessionAtTheNextRequest(): void
    {
        $rep = $this->account('rep@example.com');
        $this->api('POST', '/api/v1/auth/logout');
        $this->login('rep@example.com');
        self::assertSame(200, $this->api('GET', '/api/v1/auth/me'));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fresh = $em->find(Account::class, $rep->id());
        \assert($fresh instanceof Account);
        $fresh->block();
        $em->flush();

        self::assertSame(401, $this->api('GET', '/api/v1/auth/me'));
    }

    public function testDeletionKeepsAPseudonymousRow(): void
    {
        $rep = $this->account('rep@example.com');
        $id = $rep->id()->toRfc4122();

        self::assertSame(204, $this->api('DELETE', '/api/v1/admin/accounts/'.$id));
        $db = self::getContainer()->get(Connection::class);
        $row = $db->fetchAssociative('SELECT email, name, status, password_hash, rep_code FROM account WHERE id = ?', [$id]);
        self::assertSame(['email' => 'deleted-'.$id.'@invalid', 'name' => 'Usunięte konto', 'status' => 'deleted', 'password_hash' => null, 'rep_code' => null], $row);

        self::assertSame(200, $this->api('GET', '/api/v1/admin/accounts'));
        self::assertNotContains($id, array_column(self::items($this->body()), 'id'));
        self::assertSame(404, $this->api('DELETE', '/api/v1/admin/accounts/'.$id));
        self::assertSame(201, $this->api('POST', '/api/v1/admin/accounts', ['name' => 'New', 'email' => 'rep@example.com', 'repCode' => 'PH-02']), 'address and representative are free again');
    }

    public function testSharedOwnAndAdminAccountsAreProtected(): void
    {
        $shared = $this->account('handlowiec@example.com', Role::Rep, 'PH-01', 'Demo123!', shared: true);
        $otherAdmin = $this->account('boss@example.com', Role::Admin, null);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $self = $em->getRepository(Account::class)->findOneBy(['email' => 'admin@example.com']);
        \assert($self instanceof Account);

        foreach ([[$shared, 'shared_account'], [$otherAdmin, 'admin_account']] as [$target, $code]) {
            self::assertSame(403, $this->api('POST', '/api/v1/admin/accounts/'.$target->id()->toRfc4122().'/block'));
            $this->assertProblemCode($code);
            self::assertSame(403, $this->api('DELETE', '/api/v1/admin/accounts/'.$target->id()->toRfc4122()));
        }
        self::assertSame(403, $this->api('POST', '/api/v1/admin/accounts/'.$self->id()->toRfc4122().'/block'));
    }

    public function testUnknownIdsAndListShape(): void
    {
        self::assertSame(404, $this->api('POST', '/api/v1/admin/accounts/not-a-uuid/block'));
        $this->assertProblemCode('not_found');
        self::assertSame(404, $this->api('POST', '/api/v1/admin/accounts/01a202de-9ac7-7df7-9c6c-b887c5290cbf/block'));

        $this->account('rep@example.com');
        self::assertSame(200, $this->api('GET', '/api/v1/admin/accounts'));
        $items = self::items($this->body());
        self::assertSame(['admin@example.com', 'rep@example.com'], array_column($items, 'email'));
        foreach ($items as $item) {
            self::assertArrayNotHasKey('passwordHash', $item);
        }
    }

    public function testSalesRepsListMarksTakenCodes(): void
    {
        $this->account('rep@example.com', Role::Rep, 'PH-02');

        self::assertSame(200, $this->api('GET', '/api/v1/admin/sales-reps'));
        $items = self::items($this->body());
        self::assertSame([false, true, false, false, false], array_column($items, 'taken'));
    }

    public function testAuditListShowsRecentEvents(): void
    {
        self::assertSame(200, $this->api('GET', '/api/v1/admin/audit'));
        self::assertSame('auth.login', self::items($this->body())[0]['action'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private static function items(array $body): array
    {
        $items = $body['items'] ?? null;
        self::assertIsList($items);

        /** @var list<array<string, mixed>> $items */
        return $items;
    }
}
