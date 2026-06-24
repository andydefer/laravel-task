<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\WatchServiceInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\Records\FullBatchJsonResultRecord;
use AndyDefer\Task\Records\TaskExecutionJsonResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use Symfony\Component\Process\Process;

final class WatchService implements WatchServiceInterface
{
    private ?DirectiveTestingService $testingService = null;

    private bool $testingMode = false;

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
            $jsonArguments = $arguments->merge(StringTypedCollection::from(['--format=json']));
            $output = $this->callProcessTasks($jsonArguments);

            $data = json_decode($output, true);

            if ($this->isFullBatchResponse($data)) {
                $result = FullBatchJsonResultRecord::fromJson($output);
                $success = $result->total_success;
                $failed = $result->total_failed;
                $errors = $result->errors->count();
                $hasErrors = $result->has_failures;
            } else {
                $result = TaskExecutionJsonResultRecord::fromJson($output);
                $success = $result->success;
                $failed = $result->failed;
                $errors = $result->errors->count();
                $hasErrors = $result->has_failures;
            }

            return CycleResultRecord::from([
                'success' => $success,
                'failed' => $failed,
                'errors' => new CounterVO($errors),
                'hasErrors' => $hasErrors,
            ]);
        } catch (\Throwable $e) {
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
