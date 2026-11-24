<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring;

use App\Command\MonitoringTestCommand;
use App\Module\Monitoring\Scrubber;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class MonitoringTestCommandTest extends TestCase
{
    public function testLogsOneErrorWhoseSecretsTheScrubberRemoves(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $output = new BufferedOutput();
        self::assertSame(0, (new MonitoringTestCommand($logger))(new SymfonyStyle(new ArrayInput([]), $output)));

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame('error', $level);
        self::assertInstanceOf(\RuntimeException::class, $context['exception']);
        $scrubbed = Scrubber::text($message.' '.$context['exception']->getMessage()).json_encode(Scrubber::array(['email' => $context['email']]));
        self::assertStringNotContainsString('example.com', $scrubbed);
        self::assertStringNotContainsString('0123456789abcdef', $scrubbed);
        self::assertStringContainsString('Monitoring test', $scrubbed);
    }
}
