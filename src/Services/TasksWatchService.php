<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\TasksWatchServiceInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\Structs\BatchResultStruct;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Symfony\Component\Process\Process;

class TasksWatchService implements TasksWatchServiceInterface
{
    private ?DirectiveTestingService $testingService = null;

    private bool $testingMode = false;

    /**
     * Active le mode testing avec le DirectiveTestingService.
     */
    public function enableTestingMode(DirectiveTestingService $testingService): void
    {
        $this->testingMode = true;
        $this->testingService = $testingService;
    }

    /**
     * Désactive le mode testing.
     */
    public function disableTestingMode(): void
    {
        $this->testingMode = false;
        $this->testingService = null;
    }

    public function executeCycle(
        int $cycleNumber,
        StringTypedCollection $arguments,
        Iso8601DateTimeVO $cycleStartedAt
    ): CycleResultRecord {
        try {
            // ✅ Ajouter --format=json aux arguments
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
                message: (string) $e,
                hasErrors: true,
            );
        }
    }

    /**
     * ✅ Parse la sortie JSON et retourne directement le Struct
     */
    private function parseJsonStructOutput(string $output): BatchResultStruct
    {

        return BatchResultStruct::fromJson($output);
    }

    public function buildProcessTasksArguments(
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

    public function callProcessTasks(StringTypedCollection $arguments): string
    {
        // ✅ Mode testing : utiliser DirectiveTestingService
        if ($this->testingMode && $this->testingService !== null) {
            // Construire les arguments pour la directive process-tasks
            $args = [];
            foreach ($arguments->toArray() as $arg) {
                $args[] = $arg;
            }

            // Exécuter la directive via DirectiveTestingService
            $response = $this->testingService->run(
                ProcessTasksDirective::class,
                $args
            );

            // Retourner la sortie (JSON)
            return $response->output;
        }

        // ✅ Mode production : appel système via Process
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

    public function calculateElapsedSeconds(?Iso8601DateTimeVO $start): float
    {
        if ($start === null) {
            return 0;
        }

        $end = new Iso8601DateTimeVO;

        $startDateTime = $start->toDateTime();
        $endDateTime = $end->toDateTime();

        // ✅ Obtenir le timestamp avec microsecondes
        $startFloat = $startDateTime->format('U.u');
        $endFloat = $endDateTime->format('U.u');
        $duration = ($endFloat - $startFloat);

        return $duration > 0 ? $duration : 0.01;
    }

    public function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }

        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }

        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs}s";
        }

        return implode(' ', $parts);
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

        $elapsed = $this->calculateElapsedSeconds($startedAt);

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
}
