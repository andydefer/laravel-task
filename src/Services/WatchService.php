<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\WatchServiceInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\Structs\BatchResultStruct;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Symfony\Component\Process\Process;

class WatchService implements WatchServiceInterface
{
    private ?DirectiveTestingService $testingService = null;

    private bool $testingMode = false;

    public function __construct(
        private readonly DurationFormatterService $formatter,
    ) {}

    public function enableTestingMode(DirectiveTestingService $testingService): void
    {
        $this->testingMode = true;
        $this->testingService = $testingService;
    }

    public function disableTestingMode(): void
    {
        $this->testingMode = false;
        $this->testingService = null;
    }

    public function isTestingMode(): bool
    {
        return $this->testingMode;
    }

    public function buildArguments(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?int $limit,
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
            $arguments->add("--limit={$limit}");
        }

        if ($verbose) {
            $arguments->add('--verbose');
        }

        return $arguments;
    }

    public function executeCycle(
        int $cycleNumber,
        StringTypedCollection $arguments,
        Iso8601DateTimeVO $cycleStartedAt
    ): CycleResultRecord {
        try {
            $jsonArguments = $arguments->merge(StringTypedCollection::from(['--format=json']));
            $output = $this->callProcessTasks($jsonArguments);
            $result = $this->parseJsonStructOutput($output);

            return new CycleResultRecord(
                success: $result->success,
                failed: $result->failed,
                errors: $result->errors->count(),
                hasErrors: $result->has_failures,
            );
        } catch (\Throwable $e) {
            return new CycleResultRecord(
                success: 0,
                failed: 0,
                errors: 1,
                hasErrors: true,
                message: $e->getMessage(),
            );
        }
    }

    private function callProcessTasks(StringTypedCollection $arguments): string
    {
        if ($this->testingMode && $this->testingService !== null) {
            $args = [];
            foreach ($arguments->toArray() as $arg) {
                $args[] = $arg;
            }

            $response = $this->testingService->run(ProcessTasksDirective::class, $args);

            return $response->output;
        }

        $command = new StringTypedCollection;
        $command->add(PHP_BINARY);
        $command->add(base_path('vendor/bin/directive'));
        $command->add('process-tasks');
        $command = $command->merge($arguments);

        $process = new Process($command->toArray());
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
            throw new \RuntimeException('process-tasks failed: '.$errorOutput);
        }

        return $process->getOutput();
    }

    private function parseJsonStructOutput(string $output): BatchResultStruct
    {
        return BatchResultStruct::fromJson($output);
    }

    public function shouldContinue(
        bool $shouldStop,
        ?int $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool {
        if ($shouldStop) {
            return false;
        }

        if ($duration === null) {
            return true;
        }

        $elapsed = $this->formatter->calculateElapsedSeconds($startedAt);

        return $elapsed < $duration;
    }

    public function waitForInterval(int $interval, callable $shouldContinueCallback): void
    {
        for ($i = 0; $i < $interval; $i++) {
            if (! $shouldContinueCallback()) {
                break;
            }

            sleep(1);
        }
    }

    public function calculateElapsedSeconds(?Iso8601DateTimeVO $start): float
    {
        return $this->formatter->calculateElapsedSeconds($start);
    }

    public function formatDuration(int $seconds): string
    {
        return $this->formatter->formatDuration($seconds);
    }
}
