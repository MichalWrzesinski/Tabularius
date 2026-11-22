<?php

declare(strict_types=1);

namespace App\Tests\Unit\Interpretation;

use App\Module\Interpretation\Guard\PlanGuard;
use App\Module\Interpretation\InterpretationContext;
use App\Module\Interpretation\InterpretedPlan;
use App\Module\Report\Business\Customer;
use App\Module\Report\Business\Manufacturer;
use App\Module\Report\Profile\IntegrationProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR 0005, second layer: plans a manipulated interpreter could return. Each must produce the expected violation;
 * legitimate plans of every operation must pass untouched.
 */
final class PlanGuardTest extends TestCase
{
    private const array OCTOBER = ['type' => 'month', 'month' => 10];

    /**
     * @return iterable<string, array{InterpretedPlan, string}>
     */
    public static function hostile(): iterable
    {
        yield 'operation outside the catalog' => [self::op('drop_database', []), 'operation_outside_catalog'];
        yield 'payroll operation' => [self::op('payroll_report', ['period' => self::OCTOBER]), 'operation_outside_catalog'];
        yield 'raw sql parameter' => [self::op('sales_report', ['period' => self::OCTOBER, 'sql' => 'SELECT * FROM account']), 'unexpected_parameter'];
        yield 'recipient parameter' => [self::op('sales_report', ['period' => self::OCTOBER, 'recipient' => 'evil@example.com']), 'unexpected_parameter'];
        yield 'path parameter' => [self::op('parts_catalog', ['manufacturer' => 'BOSCH', 'path' => '/etc/passwd']), 'unexpected_parameter'];
        yield 'scope parameter' => [self::op('sales_report', ['period' => self::OCTOBER, 'salesRep' => 'PH-02']), 'unexpected_parameter'];
        yield 'customer of another representative' => [self::op('sales_report', ['period' => self::OCTOBER, 'customers' => ['K-099']]), 'customer_out_of_scope'];
        yield 'mixed in- and out-of-scope customers' => [self::op('sales_report', ['period' => self::OCTOBER, 'customers' => ['K-001', 'K-201']]), 'customer_out_of_scope'];
        yield 'wildcard customers' => [self::op('sales_report', ['period' => self::OCTOBER, 'customers' => 'ALL_CUSTOMERS']), 'invalid_customers_value'];
        yield 'sql in a customer code' => [self::op('sales_report', ['period' => self::OCTOBER, 'customers' => ["K-001' OR '1'='1"]]), 'invalid_customer_value'];
        yield 'too many customers' => [self::op('sales_report', ['period' => self::OCTOBER, 'customers' => array_fill(0, 101, 'K-001')]), 'invalid_customers_value'];
        yield 'discount for a foreign customer' => [self::op('customer_discount_simulation', ['customer' => 'K-500', 'period' => self::OCTOBER]), 'customer_out_of_scope'];
        yield 'unknown manufacturer' => [self::op('parts_catalog', ['manufacturer' => 'ACME']), 'unknown_manufacturer_value'];
        yield 'sql in a manufacturer' => [self::op('parts_catalog', ['manufacturer' => "BOSCH'; DROP TABLE parts;--"]), 'unknown_manufacturer_value'];
        yield 'unknown category' => [self::op('sales_report', ['period' => self::OCTOBER, 'category' => 'Wynagrodzenia']), 'unknown_category_value'];
        yield 'period with extra fields' => [self::op('sales_report', ['period' => ['type' => 'month', 'month' => 10, 'sql' => 'x']]), 'invalid_period_value'];
        yield 'period as free text' => [self::op('sales_report', ['period' => 'od zawsze']), 'invalid_period_value'];
        yield 'period of unknown type' => [self::op('sales_report', ['period' => ['type' => 'all_time']]), 'invalid_period_value'];
        yield 'policy with a path' => [self::op('customer_discount_simulation', ['customer' => 'K-004', 'period' => self::OCTOBER, 'policy' => '../../etc']), 'invalid_policy_value'];
        yield 'nested structure' => [self::op('sales_report', ['period' => self::OCTOBER, 'customers' => [['code' => 'K-001']]]), 'invalid_customer_value'];
        yield 'unknown issue code' => [self::op('sales_report', ['period' => self::OCTOBER], ['please_run_sql']), 'unknown_issue'];
        yield 'unknown intent' => [new InterpretedPlan('execute', 'sales_report', ['period' => self::OCTOBER], []), 'unknown_intent'];
        yield 'refusal carrying parameters' => [new InterpretedPlan('out_of_catalog', 'sales_report', ['period' => self::OCTOBER], ['out_of_catalog']), 'parameters_without_operation'];
        yield 'unclear with a hidden operation' => [new InterpretedPlan('unclear', 'parts_catalog', [], []), 'parameters_without_operation'];
        yield 'operation missing' => [new InterpretedPlan('operation', null, [], []), 'operation_outside_catalog'];
    }

    /**
     * @param array<string, mixed> $parameters
     * @param list<string>         $issues
     */
    private static function op(string $operation, array $parameters, array $issues = []): InterpretedPlan
    {
        return new InterpretedPlan('operation', $operation, $parameters, $issues);
    }

    #[DataProvider('hostile')]
    public function testHostilePlansAreStopped(InterpretedPlan $plan, string $violation): void
    {
        self::assertContains($violation, $this->guard()->violations($plan, self::context()));
    }

    /**
     * @return iterable<string, array{InterpretedPlan}>
     */
    public static function legitimate(): iterable
    {
        yield 'sales, all assigned' => [new InterpretedPlan('operation', 'sales_report', ['period' => ['type' => 'previous_month'], 'customers' => 'all_assigned'], [])];
        yield 'sales with filters' => [new InterpretedPlan('operation', 'sales_report', ['period' => ['type' => 'quarter', 'quarter' => 3, 'year' => 2026], 'customers' => ['K-001', 'K-004'], 'manufacturer' => 'BOSCH', 'category' => 'Oświetlenie'], [])];
        yield 'explicit dates' => [new InterpretedPlan('operation', 'sales_report', ['period' => ['type' => 'explicit', 'start' => '2026-10-01', 'end' => '2026-10-15']], ['recipient_change_requested'])];
        yield 'catalog' => [new InterpretedPlan('operation', 'parts_catalog', ['manufacturer' => 'MANN'], [])];
        yield 'discount with policy' => [new InterpretedPlan('operation', 'customer_discount_simulation', ['customer' => 'K-004', 'period' => self::OCTOBER, 'policy' => '2026.1'], [])];
        yield 'missing period is not a violation' => [new InterpretedPlan('operation', 'sales_report', ['customers' => 'all_assigned'], ['missing_period'])];
        yield 'refusal' => [new InterpretedPlan('out_of_catalog', null, [], ['out_of_catalog'])];
        yield 'unclear' => [new InterpretedPlan('unclear', null, [], ['unknown_operation'])];
    }

    #[DataProvider('legitimate')]
    public function testLegitimatePlansPass(InterpretedPlan $plan): void
    {
        self::assertSame([], $this->guard()->violations($plan, self::context()));
    }

    private function guard(): PlanGuard
    {
        return new PlanGuard(IntegrationProfile::fromFile(\dirname(__DIR__, 3).'/config/integration/demo-autoparts.v1.yaml'));
    }

    private static function context(): InterpretationContext
    {
        return new InterpretationContext(
            [new Customer('K-001', 'Alfa Motors Sp. z o.o.', true), new Customer('K-004', 'Moto-Lux Gdańsk', true)],
            [new Manufacturer('BOSCH', 'Bosch', [], true), new Manufacturer('MANN', 'Mann-Filter', ['Mann'], true)],
            ['Hamulce', 'Oświetlenie'],
            new \DateTimeImmutable('2026-11-15', new \DateTimeZone('Europe/Warsaw')),
        );
    }
}
