<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\Records\FullBatchJsonResultRecord;
use AndyDefer\Task\Records\TaskExecutionJsonResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use Symfony\Component\Process\Process;

final class WatchService implements WatchInterface
{
    private ?DirectiveTestingService $testingService = null;

    private bool $testingMode = false;

    public function __construct(
        private readonly Console $console,
    ) {}

    public function enableTestingMode(DirectiveTestingService $testingService): void
    {
        $this->testingMode = true;
        $this->testingService = $testingService;
        $this->console->info('🧪 Testing mode enabled');
    }

    public function disableTestingMode(): void
    {
        $this->testingMode = false;
        $this->testingService = null;
        $this->console->info('🔬 Testing mode disabled');
    }

    public function isTestingMode(): bool
    {
        return $this->testingMode;
    }

    public function buildArguments(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose
    ): StringTypedCollection {
        $arguments = new StringTypedCollection;

        if ($uniqueOnly) {
            $arguments->add('--unique-only');
        }

        if ($recurringOnly) {
            $arguments->add('--recurring-only');
        }

        if ($limit !== null) {
            $arguments->add("--limit={$limit->getValue()}");
        }

        if ($verbose) {
            $arguments->add('--verbose');
        }

        return $arguments;
    }

    public function executeCycle(
        CounterVO $cycleNumber,
        StringTypedCollection $arguments,
        Iso8601DateTimeVO $cycleStartedAt
    ): CycleResultRecord {
        try {
            $this->console->line(sprintf(
                '🔄 Cycle #%d started at %s',
                $cycleNumber->getValue(),
                $cycleStartedAt->format('H:i:s')
            ));

            $jsonArguments = $arguments->merge(StringTypedCollection::from(['--format=json']));
            $output = $this->callProcessTasks($jsonArguments);

            $cleanOutput = $this->stripAnsi($output);

            $data = json_decode($cleanOutput, true);

            if ($this->isFullBatchResponse($data)) {
                $result = FullBatchJsonResultRecord::from($cleanOutput);
                $success = $result->total_success;
                $failed = $result->total_failed;
                $errors = $result->errors->count();
                $hasErrors = $result->has_failures;
            } else {
                $result = TaskExecutionJsonResultRecord::from($cleanOutput);
                $success = $result->success;
                $failed = $result->failed;
                $errors = $result->errors->count();
                $hasErrors = $result->has_failures;
            }

            $elapsed = $cycleStartedAt->elapsed();

            $this->console->info(sprintf(
                '✅ %d tasks succeeded, ❌ %d tasks failed (%.2f s)',
                $success->getValue(),
                $failed->getValue(),
                $elapsed->seconds
            ));

            return CycleResultRecord::from([
                'success' => $success,
                'failed' => $failed,
                'errors' => new CounterVO($errors),
                'hasErrors' => $hasErrors,
            ]);
        } catch (\Throwable $e) {
            $this->console->error('❌ Cycle failed: '.$e->getMessage());

            return CycleResultRecord::from([
                'success' => new CounterVO(0),
                'failed' => new CounterVO(0),
                'errors' => new CounterVO(1),
                'hasErrors' => true,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function callProcessTasks(StringTypedCollection $arguments): string
    {
        // ✅ Mode test : utiliser DirectiveTestingService
        if ($this->testingMode && $this->testingService !== null) {
            $args = [];
            foreach ($arguments->toArray() as $arg) {
                $args[] = $arg;
            }

            $this->console->logDebug('🔬 Running in testing mode...');
            $response = $this->testingService->run(ProcessTasksDirective::class, $args);

            return $this->stripAnsi($response->output);
        }

        // ✅ Mode réel : exécuter la commande
        $directivePath = $this->getDirectivePath();

        $command = new StringTypedCollection;
        $command->add(PHP_BINARY);
        $command->add($directivePath);
        $command->add('process-tasks');
        $command = $command->merge($arguments);

        $this->console->logDebug('🔧 Executing: '.$command->join(' '));

        $process = new Process($command->toArray());
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
            throw new \RuntimeException('process-tasks failed: '.$errorOutput);
        }

        return $this->stripAnsi($process->getOutput());
    }

    /**
     * Trouve le chemin absolu du fichier directive.
     *
     * @return string Le chemin absolu du fichier directive
     *
     * @throws \RuntimeException Si le fichier directive n'est pas trouvé
     */
    private function getDirectivePath(): string
    {
        $path = getcwd().'/vendor/bin/directive';

        if (file_exists($path)) {
            return $path;
        }

        throw new \RuntimeException('Could not find directive executable at: '.$path);
    }

    private function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    private function isFullBatchResponse(array $data): bool
    {
        return isset($data['unique']) && isset($data['recurring']);
    }

    public function shouldContinue(
        bool $shouldStop,
        ?DurationVO $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool {
        if ($shouldStop) {
            return false;
        }

        if ($duration === null) {
            return true;
        }

        $elapsed = $startedAt !== null ? $startedAt->elapsed()->seconds : 0;

        return $elapsed < $duration->seconds;
    }

    public function waitForInterval(DurationVO $interval, callable $shouldContinueCallback): void
    {
        $intervalSeconds = (int) $interval->seconds;

        for ($i = 0; $i < $intervalSeconds; $i++) {
            if (! $shouldContinueCallback()) {
                break;
            }

            if ($i % 10 === 0 && $i > 0) {
                $this->console->logDebug(sprintf('⏳ Waiting... %d/%d seconds', $i, $intervalSeconds));
            }

            sleep(1);
        }
    }

    public function calculateElapsedSeconds(?Iso8601DateTimeVO $start): float
    {
        if ($start === null) {
            return 0.0;
        }

        return $start->elapsed()->seconds;
    }

    public function formatDuration(DurationVO $duration): string
    {
        return $duration->format();
    }
}
