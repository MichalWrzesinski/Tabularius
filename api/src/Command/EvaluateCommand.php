<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Evaluation\Evaluator;
use App\Module\Interpretation\OpenAi\MemoryUsage;
use App\Module\Interpretation\OpenAi\OpenAiInterpreter;
use App\Module\Interpretation\RuleInterpreter;
use App\Module\Report\Profile\IntegrationProfile;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Interpretation evaluation (TASK-012): runs a set from api/evaluation/ through screen → interpreter → guard and
 * writes a JSON report with versions, metrics, latency and cost. The LLM run is capped by --max-usd (the run stops
 * calling the provider when the cap is reached; remaining cases are reported as errors).
 */
#[AsCommand(name: 'app:eval', description: 'Evaluate an interpreter on an evaluation set')]
final class EvaluateCommand
{
    public function __construct(
        private readonly Evaluator $evaluator,
        private readonly RuleInterpreter $rules,
        private readonly HttpClientInterface $http,
        private readonly IntegrationProfile $profile,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire(env: 'OPENAI_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'OPENAI_MODEL')]
        private readonly string $model,
        #[Autowire(env: 'float:OPENAI_PRICE_INPUT_PER_MTOK')]
        private readonly float $priceInput,
        #[Autowire(env: 'float:OPENAI_PRICE_OUTPUT_PER_MTOK')]
        private readonly float $priceOutput,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'dev or final')]
        string $set = 'dev',
        #[Option(description: 'rules or openai')]
        string $interpreter = 'rules',
        #[Option(description: 'Cost cap of an LLM run in USD')]
        float $maxUsd = 1.0,
        #[Option(description: 'Write the JSON report to this file')]
        ?string $out = null,
    ): int {
        if (!\in_array($set, ['dev', 'final'], true) || !\in_array($interpreter, ['rules', 'openai'], true)) {
            $io->error('--set must be dev or final, --interpreter rules or openai.');

            return Command::INVALID;
        }
        $file = $this->projectDir.'/evaluation/'.$set.'.json';
        $raw = (string) file_get_contents($file);
        $data = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        \assert(\is_array($data));
        $usage = new MemoryUsage();
        if ('openai' === $interpreter) {
            if ('' === trim($this->apiKey)) {
                $io->error('OPENAI_API_KEY is not set.');

                return Command::FAILURE;
            }
            $subject = new OpenAiInterpreter($this->http, $this->profile, $usage, new NullLogger(), $this->apiKey, $this->model, $maxUsd, $this->priceInput, $this->priceOutput);
        } else {
            $subject = $this->rules;
        }

        /** @var array{context: array{customers: list<array{code: string, name: string, active?: bool}>, manufacturers: list<array{code: string, name: string, aliases?: list<string>}>, categories: list<string>, reference: string}, cases: list<array{id: string, category: string, text: string, expect: array<string, mixed>}>} $data */
        $run = $this->evaluator->run($data, $subject, static function (int $done, int $total) use ($io): void {
            if (0 === $done % 20 || $done === $total) {
                $io->writeln(\sprintf('  %d/%d', $done, $total));
            }
        });
        $report = [
            'set' => $set,
            'setSha256' => hash('sha256', $raw),
            'interpreter' => $subject->version(),
            'at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'usage' => [
                'calls' => \count($usage->calls),
                'inputTokens' => array_sum(array_column($usage->calls, 'input')),
                'outputTokens' => array_sum(array_column($usage->calls, 'output')),
                'costUsd' => round($usage->spentToday(), 6),
            ],
            'summary' => $run['summary'],
            'results' => $run['results'],
        ];

        $rows = [];
        foreach ($run['summary']['categories'] as $category => $s) {
            $rows[] = [$category, $s['n'], \sprintf('%d/%d', $s['correct'], $s['n']), $s['withExpectedPlan'] > 0 ? \sprintf('%d/%d', $s['operationCorrect'], $s['withExpectedPlan']) : '—', $s['withExpectedPlan'] > 0 ? \sprintf('%d/%d', $s['parametersCorrect'], $s['withExpectedPlan']) : '—', $s['attackEffects'] ?? '—', $s['errors']];
        }
        $io->title(\sprintf('%s · %s · sha256 %s', $set, $subject->version(), substr($report['setSha256'], 0, 12)));
        $io->table(['category', 'n', 'correct', 'operation', 'parameters', 'attack effects', 'errors'], $rows);
        $io->writeln(\sprintf('Calls %d, tokens %d/%d, cost USD %.4f, latency p50 %d ms, p95 %d ms', $report['usage']['calls'], $report['usage']['inputTokens'], $report['usage']['outputTokens'], $report['usage']['costUsd'], $run['summary']['latencyMs']['p50'], $run['summary']['latencyMs']['p95']));
        if (null !== $out) {
            file_put_contents($out, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
            $io->success('Report written to '.$out);
        }

        return Command::SUCCESS;
    }
}
