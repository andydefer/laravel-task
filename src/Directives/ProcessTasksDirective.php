<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\FullBatchJsonResultRecord;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\TaskExecutionJsonResultRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

final class ProcessTasksDirective extends AbstractDirective
{
    public function getSignature(): string
    {
        return 'process-tasks {--unique-only} {--recurring-only} {--verbose} {--limit=} {--format=}';
    }

    public function getDescription(): string
    {
        return 'Process all pending tasks in a single batch (no polling, no waiting)';
    }

    public function getAliases(): StringTypedCollection
    {
        $aliases = new StringTypedCollection;
        $aliases->add('task-process');
        $aliases->add('tasks-process');

        return $aliases;
    }

    public function execute(): ExitCode
    {
        // ✅ TOUTE L'INITIALISATION ICI
        $app = $this->getLaravel();

        if ($app === null) {
            throw new \RuntimeException('Laravel container is not available');
        }

        $console = $app->make(Console::class);

        $validationResult = $this->validateOptions($console);

        if ($validationResult !== null) {
            return $validationResult;
        }

        $uniqueOnly = $this->hasOption('unique-only');
        $recurringOnly = $this->hasOption('recurring-only');
        $verbose = $this->hasOption('verbose');
        $limit = $this->getValidatedLimit();
        $format = $this->option('format') ?? 'text';

        $uniqueService = $this->getUniqueTaskService();
        $recurringService = $this->getRecurringTaskService();

        $hasFailures = false;

        if ($uniqueOnly) {

            $result = $this->processUniqueOnly($uniqueService, $limit);
            $hasFailures = $result->failed->isPositive();

            if ($format === 'json') {
                $this->outputUniqueJson($console, $result);
            } else {
                $this->displayProcessingStart($console, $limit);
                $this->displayUniqueResults($console, $result);
                $this->displayErrorsIfVerbose($console, $verbose, $result->errors, 'Unique');
            }
        } elseif ($recurringOnly) {

            $result = $this->processRecurringOnly($recurringService, $limit);
            $hasFailures = $result->failed->isPositive();

            if ($format === 'json') {
                $this->outputRecurringJson($console, $result);
            } else {
                $this->displayProcessingStart($console, $limit);
                $this->displayRecurringResults($console, $result);
                $this->displayErrorsIfVerbose($console, $verbose, $result->errors, 'Recurring');
            }
        } else {

            $uniqueResult = $this->processUniqueOnly($uniqueService, $limit);
            $recurringResult = $this->processRecurringOnly($recurringService, $limit);

            $hasFailures = $uniqueResult->failed->isPositive() || $recurringResult->failed->isPositive();

            if ($format === 'json') {
                $this->outputFullJson($console, $uniqueResult, $recurringResult);

            } else {
                $this->displayProcessingStart($console, $limit);
                $this->displayFullResults($console, $uniqueResult, $recurringResult);
                $this->displayFullErrorsIfVerbose($console, $verbose, $uniqueResult, $recurringResult);
            }
        }

        return $hasFailures ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    private function getUniqueTaskService(): UniqueTaskServiceInterface
    {
        $laravel = $this->getLaravel();

        if ($laravel === null) {
            throw new \RuntimeException('Laravel container is not available. Task processing requires Laravel.');
        }

        return $laravel->make(UniqueTaskServiceInterface::class);
    }

    private function getRecurringTaskService(): RecurringTaskServiceInterface
    {
        $laravel = $this->getLaravel();

        if ($laravel === null) {
            throw new \RuntimeException('Laravel container is not available. Task processing requires Laravel.');
        }

        return $laravel->make(RecurringTaskServiceInterface::class);
    }

    private function validateOptions(Console $console): ?ExitCode
    {
        $uniqueOnly = $this->hasOption('unique-only');
        $recurringOnly = $this->hasOption('recurring-only');

        if ($uniqueOnly && $recurringOnly) {
            $console->error('Cannot use both --unique-only and --recurring-only');

            return ExitCode::INVALID_ARGUMENT;
        }

        $limit = $this->option('limit');

        if ($limit !== null && (int) $limit <= 0) {
            $console->error('Limit must be a positive integer');

            return ExitCode::INVALID_ARGUMENT;
        }

        $format = $this->option('format');

        if ($format !== null && ! in_array($format, ['text', 'json'], true)) {
            $console->error('Format must be "text" or "json"');

            return ExitCode::INVALID_ARGUMENT;
        }

        return null;
    }

    private function getValidatedLimit(): ?int
    {
        $limit = $this->option('limit');

        return $limit !== null ? (int) $limit : null;
    }

    private function displayProcessingStart(Console $console, ?int $limit): void
    {
        $console->info('Processing tasks...');

        if ($limit !== null) {
            $console->info('Limit: '.$limit.' tasks');
        }
    }

    // ==================== UNIQUE TASKS ====================

    private function processUniqueOnly(
        UniqueTaskServiceInterface $service,
        ?int $limit
    ): ProcessResultRecord {
        if ($limit !== null) {
            return $service->process(new LimitVO($limit));
        }

        return $service->process();
    }

    private function displayUniqueResults(Console $console, ProcessResultRecord $result): void
    {
        $total = $result->success->getValue() + $result->failed->getValue();

        $console->line();
        $console->title('=== Unique Batch Results ===');
        $console->info('  Success: '.$result->success->getValue());
        $console->error('  Failed: '.$result->failed->getValue());
        $console->info('  Total: '.$total);
    }

    private function outputUniqueJson(Console $console, ProcessResultRecord $result): void
    {
        $endedAt = new Iso8601DateTimeVO;
        $duration = $this->getDurationMilliseconds($result->started_at);
        $total = $result->success->getValue() + $result->failed->getValue();

        $jsonResult = TaskExecutionJsonResultRecord::from([
            'started_at' => $result->started_at,
            'ended_at' => $endedAt,
            'duration_ms' => $duration,
            'success' => $result->success->getValue(),
            'failed' => $result->failed->getValue(),
            'total' => $total,
            'errors' => $result->errors,
            'has_failures' => $result->failed->isPositive(),
            'type' => 'unique',
        ]);

        $console->jsonRaw($jsonResult->toArray());
    }

    // ==================== RECURRING TASKS ====================

    private function processRecurringOnly(
        RecurringTaskServiceInterface $service,
        ?int $limit
    ): ProcessResultRecord {
        if ($limit !== null) {
            return $service->process(new LimitVO($limit));
        }

        return $service->process();
    }

    private function displayRecurringResults(Console $console, ProcessResultRecord $result): void
    {
        $total = $result->success->getValue() + $result->failed->getValue();

        $console->line();
        $console->title('=== Recurring Batch Results ===');
        $console->info('  Success: '.$result->success->getValue());
        $console->error('  Failed: '.$result->failed->getValue());
        $console->info('  Total: '.$total);
    }

    private function outputRecurringJson(Console $console, ProcessResultRecord $result): void
    {
        $endedAt = new Iso8601DateTimeVO;
        $duration = $this->getDurationMilliseconds($result->started_at);
        $total = $result->success->getValue() + $result->failed->getValue();

        $jsonResult = TaskExecutionJsonResultRecord::from([
            'started_at' => $result->started_at,
            'ended_at' => $endedAt,
            'duration_ms' => $duration,
            'success' => $result->success->getValue(),
            'failed' => $result->failed->getValue(),
            'total' => $total,
            'errors' => $result->errors,
            'has_failures' => $result->failed->isPositive(),
            'type' => 'recurring',
        ]);

        $console->jsonRaw($jsonResult->toArray());
    }

    // ==================== FULL (BOTH) ====================

    private function displayFullResults(
        Console $console,
        ProcessResultRecord $uniqueResult,
        ProcessResultRecord $recurringResult
    ): void {
        $totalSuccess = $uniqueResult->success->getValue() + $recurringResult->success->getValue();
        $totalFailed = $uniqueResult->failed->getValue() + $recurringResult->failed->getValue();
        $totalProcessed = $totalSuccess + $totalFailed;
        $hasFailures = $uniqueResult->failed->isPositive() || $recurringResult->failed->isPositive();

        $console->line();
        $console->title('=== Batch Results ===');
        $console->info('  Unique:    ✅ '.$uniqueResult->success->getValue().', ❌ '.$uniqueResult->failed->getValue());
        $console->info('  Recurring: ✅ '.$recurringResult->success->getValue().', ❌ '.$recurringResult->failed->getValue());
        $console->info('  Total:     ✅ '.$totalSuccess.', ❌ '.$totalFailed.', 📦 '.$totalProcessed);
        $console->info('  Has failures: '.($hasFailures ? 'Yes' : 'No'));
    }

    private function outputFullJson(
        Console $console,
        ProcessResultRecord $uniqueResult,
        ProcessResultRecord $recurringResult
    ): void {
        $endedAt = new Iso8601DateTimeVO;
        $duration = $this->getDurationMilliseconds($uniqueResult->started_at);

        $totalSuccess = $uniqueResult->success->getValue() + $recurringResult->success->getValue();
        $totalFailed = $uniqueResult->failed->getValue() + $recurringResult->failed->getValue();
        $totalProcessed = $totalSuccess + $totalFailed;
        $hasFailures = $uniqueResult->failed->isPositive() || $recurringResult->failed->isPositive();

        $uniqueBatch = BatchResultRecord::from([
            'success' => $uniqueResult->success->getValue(),
            'failed' => $uniqueResult->failed->getValue(),
            'errors' => $uniqueResult->errors,
        ]);

        $recurringBatch = BatchResultRecord::from([
            'success' => $recurringResult->success->getValue(),
            'failed' => $recurringResult->failed->getValue(),
            'errors' => $recurringResult->errors,
        ]);

        $allErrors = new TaskErrorRecordCollection;
        foreach ($uniqueResult->errors as $error) {
            $allErrors->add($error);
        }
        foreach ($recurringResult->errors as $error) {
            $allErrors->add($error);
        }

        $fullResult = FullBatchJsonResultRecord::from([
            'started_at' => $uniqueResult->started_at,
            'ended_at' => $endedAt,
            'duration_ms' => $duration,
            'total_success' => $totalSuccess,
            'total_failed' => $totalFailed,
            'total' => $totalProcessed,
            'errors' => $allErrors,
            'has_failures' => $hasFailures,
            'unique' => $uniqueBatch,
            'recurring' => $recurringBatch,
        ]);

        $console->jsonRaw($fullResult->toArray());
    }

    // ==================== ERRORS ====================

    private function displayErrorsIfVerbose(
        Console $console,
        bool $verbose,
        iterable $errors,
        string $type
    ): void {
        if (! $verbose) {
            return;
        }

        $errorsArray = iterator_to_array($errors);
        if (empty($errorsArray)) {
            return;
        }

        $console->line();
        $console->error('=== Failed '.$type.' Tasks ===');

        foreach ($errorsArray as $error) {
            $displayName = $error->alias ?? $error->identifier;
            $console->error('    ❌ '.$displayName.': '.$error->error);
        }
    }

    private function displayFullErrorsIfVerbose(
        Console $console,
        bool $verbose,
        ProcessResultRecord $uniqueResult,
        ProcessResultRecord $recurringResult
    ): void {
        if (! $verbose) {
            return;
        }

        $hasUniqueErrors = ! $uniqueResult->errors->isEmpty();
        $hasRecurringErrors = ! $recurringResult->errors->isEmpty();

        if (! $hasUniqueErrors && ! $hasRecurringErrors) {
            return;
        }

        $console->line();
        $console->error('=== Failed Tasks ===');

        if ($hasUniqueErrors) {
            $console->info('  Unique tasks:');
            foreach ($uniqueResult->errors as $error) {
                $displayName = $error->alias ?? $error->identifier;
                $console->error('    ❌ '.$displayName.': '.$error->description);
            }
        }

        if ($hasRecurringErrors) {
            $console->info('  Recurring tasks:');
            foreach ($recurringResult->errors as $error) {
                $displayName = $error->alias ?? $error->identifier;
                $console->error('    ❌ '.$displayName.': '.$error->description);
            }
        }
    }

    private function getDurationMilliseconds(Iso8601DateTimeVO $start): int
    {
        $startTimestamp = $start->toCarbon()->getTimestamp();
        $endTimestamp = (new Iso8601DateTimeVO)->toCarbon()->getTimestamp();

        return (int) (($endTimestamp - $startTimestamp) * 1000);
    }
}
