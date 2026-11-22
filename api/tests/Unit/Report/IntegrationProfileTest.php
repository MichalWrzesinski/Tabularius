<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\Module\Report\Profile\IntegrationProfile;
use PHPUnit\Framework\TestCase;

final class IntegrationProfileTest extends TestCase
{
    public function testLoadsTheDemoProfile(): void
    {
        $profile = IntegrationProfile::fromFile(\dirname(__DIR__, 3).'/config/integration/demo-autoparts.v1.yaml');

        self::assertSame('demo-autoparts v1', $profile->label());
        self::assertSame(['sales_report', 'parts_catalog', 'customer_discount_simulation'], array_keys($profile->operations));
        self::assertSame(['manufacturerName', 'partNumber', 'name', 'category', 'unit', 'listPriceNet'], $profile->operation('parts_catalog')->permittedFields);
        self::assertTrue($profile->operation('sales_report')->parameters['period']);
        self::assertFalse($profile->operation('sales_report')->parameters['manufacturer']);
        self::assertSame(50000, $profile->maxRows);
        self::assertContains('Hamulce', $profile->categories);
    }

    public function testOperationsOutsideTheCatalogAreRejected(): void
    {
        $profile = IntegrationProfile::fromFile(\dirname(__DIR__, 3).'/config/integration/demo-autoparts.v1.yaml');

        $this->expectException(\OutOfBoundsException::class);
        $profile->operation('payroll_report');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function broken(): iterable
    {
        $valid = [
            'id' => 'p', 'version' => 1, 'currency' => 'PLN', 'timezone' => 'Europe/Warsaw', 'categories' => ['A'], 'limits' => ['max_rows' => 10],
            'operations' => ['op' => ['label' => 'L', 'file_name' => 'f', 'columns' => ['a'], 'parameters' => []]],
        ];
        yield 'missing id' => [array_diff_key($valid, ['id' => 0])];
        yield 'version as string' => [['version' => '1'] + $valid];
        yield 'operations not a map' => [['operations' => 'x'] + $valid];
        yield 'operation not a map' => [['operations' => ['op' => 'x']] + $valid];
        yield 'empty columns' => [['operations' => ['op' => ['label' => 'L', 'file_name' => 'f', 'columns' => [], 'parameters' => []]]] + $valid];
        yield 'columns not strings' => [['operations' => ['op' => ['label' => 'L', 'file_name' => 'f', 'columns' => [1], 'parameters' => []]]] + $valid];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('broken')]
    public function testRejectsBrokenProfiles(array $data): void
    {
        $this->expectException(\UnexpectedValueException::class);
        IntegrationProfile::fromArray($data);
    }

    public function testRejectsNonMappingFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'profile');
        self::assertIsString($file);
        file_put_contents($file, "- just\n- a list\n");

        try {
            $this->expectException(\UnexpectedValueException::class);
            IntegrationProfile::fromFile($file);
        } finally {
            unlink($file);
        }
    }
}
