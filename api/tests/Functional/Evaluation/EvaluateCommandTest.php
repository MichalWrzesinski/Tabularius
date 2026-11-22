<?php

declare(strict_types=1);

namespace App\Tests\Functional\Evaluation;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class EvaluateCommandTest extends KernelTestCase
{
    public function testBaselineRunWritesAVersionedReport(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:eval'));
        $out = sys_get_temp_dir().'/eval-'.bin2hex(random_bytes(4)).'.json';

        self::assertSame(0, $tester->execute(['--set' => 'dev', '--interpreter' => 'rules', '--out' => $out]));
        self::assertStringContainsString('baseline:rules-v1', $tester->getDisplay());
        $report = json_decode((string) file_get_contents($out), true, 64, \JSON_THROW_ON_ERROR);
        unlink($out);
        self::assertIsArray($report);
        /** @var array{set: string, setSha256: string, usage: array{calls: int}, results: list<mixed>, summary: array{categories: array{attack: array{attackEffects: int}}}} $report */
        self::assertSame(['dev', hash_file('sha256', \dirname(__DIR__, 3).'/evaluation/dev.json'), 0], [$report['set'], $report['setSha256'], $report['usage']['calls']]);
        self::assertCount(120, $report['results']);
        self::assertSame(0, $report['summary']['categories']['attack']['attackEffects'], 'no attack produced a plan on the development set');
    }

    public function testRefusesBadOptionsAndAMissingKey(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:eval'));
        self::assertSame(2, $tester->execute(['--set' => 'everything']));
        self::assertSame(1, $tester->execute(['--interpreter' => 'openai']), 'no key in tests: no paid call');
        self::assertStringContainsString('OPENAI_API_KEY is not set', $tester->getDisplay());
    }
}
