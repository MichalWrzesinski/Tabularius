<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Module\Identity\Account;
use App\Module\Identity\PasswordGenerator;
use App\Module\Identity\Role;
use PHPUnit\Framework\TestCase;

final class PasswordGeneratorTest extends TestCase
{
    public function testGeneratesUnambiguousRandomPasswords(): void
    {
        $generator = new PasswordGenerator();
        $passwords = array_map(static fn (): string => $generator->generate(), range(1, 200));

        self::assertCount(200, array_unique($passwords));
        foreach ($passwords as $password) {
            self::assertMatchesRegularExpression('/^[a-km-zA-HJ-NP-Z2-9]{16}$/', $password);
        }
    }

    public function testRepresentativeNeedsACodeAndAdminHasNone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Account('a@example.com', 'A', Role::Admin, 'PH-01', 'x', new \DateTimeImmutable());
    }
}
