<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Module\Identity\Role;

final class AuthTest extends IdentityTestCase
{
    public function testLoginReturnsTheAccountAndStartsASession(): void
    {
        $this->account('anna@example.com');

        self::assertSame(200, $this->login('ANNA@example.com '));
        self::assertSame(['email' => 'anna@example.com', 'role' => 'rep', 'repCode' => 'PH-02', 'shared' => false], array_intersect_key($this->body(), array_flip(['email', 'role', 'repCode', 'shared'])));
        self::assertArrayNotHasKey('password', $this->body());

        self::assertSame(200, $this->api('GET', '/api/v1/auth/me'));
        self::assertSame('anna@example.com', $this->body()['email'] ?? null);
    }

    public function testSessionProbeIsPublic(): void
    {
        self::assertSame(200, $this->api('GET', '/api/v1/auth/session'));
        self::assertSame(['me' => null], $this->body());

        $this->account('anna@example.com');
        $this->login('anna@example.com');
        self::assertSame(200, $this->api('GET', '/api/v1/auth/session'));
        $me = $this->body()['me'] ?? null;
        self::assertIsArray($me);
        self::assertSame('anna@example.com', $me['email'] ?? null);
    }

    public function testSameAnswerForUnknownWrongPasswordAndBlocked(): void
    {
        $blocked = $this->account('blocked@example.com', Role::Rep, 'PH-03');
        $blocked->block();
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $this->account('anna@example.com');

        foreach ([['nobody@example.com', 'x'], ['anna@example.com', 'wrong'], ['blocked@example.com', self::PASSWORD]] as [$email, $password]) {
            self::assertSame(401, $this->login($email, $password));
            $this->assertProblemCode('invalid_credentials');
        }
    }

    public function testLoginIsThrottled(): void
    {
        $this->account('anna@example.com');
        for ($i = 0; $i < 5; ++$i) {
            $this->login('anna@example.com', 'wrong');
        }

        self::assertSame(429, $this->login('anna@example.com'));
        $this->assertProblemCode('too_many_attempts');
    }

    public function testStateChangingRequestsNeedTheSameOriginHeader(): void
    {
        $this->account('anna@example.com');

        self::assertSame(403, $this->api('POST', '/api/v1/auth/login', ['email' => 'anna@example.com', 'password' => self::PASSWORD], csrf: false));
        $this->assertProblemCode('csrf');

        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TABULARIUS_REQUEST' => '1', 'HTTP_SEC_FETCH_SITE' => 'cross-site'], content: '{}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousAndWrongRoleAreRefused(): void
    {
        self::assertSame(401, $this->api('GET', '/api/v1/auth/me'));
        $this->assertProblemCode('unauthenticated');

        $this->account('anna@example.com');
        $this->login('anna@example.com');
        self::assertSame(403, $this->api('GET', '/api/v1/admin/accounts'));
        $this->assertProblemCode('forbidden');
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->account('anna@example.com');
        $this->login('anna@example.com');

        $this->api('POST', '/api/v1/auth/logout');
        self::assertSame(401, $this->api('GET', '/api/v1/auth/me'));
    }

    public function testPasswordChange(): void
    {
        $this->account('anna@example.com');
        $this->login('anna@example.com');

        self::assertSame(422, $this->api('POST', '/api/v1/auth/password', ['currentPassword' => 'wrong', 'newPassword' => self::NEW_PASSWORD]));
        $this->assertProblemCode('invalid_current_password');
        self::assertSame(422, $this->api('POST', '/api/v1/auth/password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'short']));
        $this->assertProblemCode('weak_password');
        self::assertSame(204, $this->api('POST', '/api/v1/auth/password', ['currentPassword' => self::PASSWORD, 'newPassword' => self::NEW_PASSWORD]));

        $this->api('POST', '/api/v1/auth/logout');
        self::assertSame(401, $this->login('anna@example.com'));
        self::assertSame(200, $this->login('anna@example.com', self::NEW_PASSWORD));
    }

    public function testSharedDemoAccountCannotChangeItsPassword(): void
    {
        $this->account('handlowiec@example.com', Role::Rep, 'PH-01', 'Demo123!', shared: true);
        $this->login('handlowiec@example.com', 'Demo123!');

        self::assertSame(403, $this->api('POST', '/api/v1/auth/password', ['currentPassword' => 'Demo123!', 'newPassword' => self::NEW_PASSWORD]));
        $this->assertProblemCode('shared_account');
    }

    public function testPasswordResetDoesNotRevealAccounts(): void
    {
        $this->account('anna@example.com');

        self::assertSame(202, $this->api('POST', '/api/v1/auth/password-reset', ['email' => 'nobody@example.com']));
        $unknown = (string) $this->client->getResponse()->getContent();
        self::assertEmailCount(0);

        self::assertSame(202, $this->api('POST', '/api/v1/auth/password-reset', ['email' => 'anna@example.com']));
        self::assertSame($unknown, (string) $this->client->getResponse()->getContent());
        self::assertEmailCount(1);
        self::assertSame(401, $this->login('anna@example.com'), 'old password no longer works');
    }

    public function testPasswordResetIsLimitedPerAddressRegardlessOfExistence(): void
    {
        $this->account('anna@example.com');

        foreach (['anna@example.com', 'nobody@example.com'] as $email) {
            for ($i = 0; $i < 3; ++$i) {
                self::assertSame(202, $this->api('POST', '/api/v1/auth/password-reset', ['email' => $email]));
            }
            self::assertSame(429, $this->api('POST', '/api/v1/auth/password-reset', ['email' => ' '.strtoupper($email)]));
            $this->assertProblemCode('too_many_attempts');
            self::assertEmailCount(0, message: 'a refused request sends no mail');
        }
    }

    public function testPasswordResetIsLimitedPerClient(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(202, $this->api('POST', '/api/v1/auth/password-reset', ['email' => "user{$i}@example.com"]));
        }
        self::assertSame(429, $this->api('POST', '/api/v1/auth/password-reset', ['email' => 'another@example.com']));
    }

    public function testMalformedBodiesAreBadRequests(): void
    {
        $this->client->request('POST', '/api/v1/auth/password-reset', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TABULARIUS_REQUEST' => '1'], content: '{oops');
        self::assertResponseStatusCodeSame(400);
        self::assertSame(400, $this->api('POST', '/api/v1/auth/password-reset', ['mail' => 'x']));
        self::assertSame(400, $this->api('POST', '/api/v1/auth/password-reset', null));
    }
}
