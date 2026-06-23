<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Collections\TaskErrorStructCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\RecurringBatchResultRecord;
use AndyDefer\Task\Records\UniqueBatchResultRecord;
use AndyDefer\Task\Structs\BatchResultStruct;
use AndyDefer\Task\Structs\RecurringBatchResultStruct;
use AndyDefer\Task\Structs\TaskErrorStruct;
use AndyDefer\Task\Structs\UniqueBatchResultStruct;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class ProcessTasksDirective extends AbstractDirective
{
    public function __construct(
        DirectiveContext $context,
        DirectiveInteractionService $interaction,
    ) {
        parent::__construct($context, $interaction);
    }

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
        $validationResult = $this->validateOptions();

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

        $startedAt = new Iso8601DateTimeVO;
        $hasFailures = false;

        if ($uniqueOnly) {
            $record = $this->processUniqueOnly($uniqueService, $startedAt, $limit);
            $hasFailures = $record->failed->value > 0;

            if ($format === 'json') {
                $this->outputUniqueJsonStruct($record);
            } else {
                $this->displayProcessingStart($limit);
                $this->displayUniqueResults($record);
                $this->displayUniqueErrorsIfVerbose($verbose, $record->errors);
            }
        } elseif ($recurringOnly) {
            $record = $this->processRecurringOnly($recurringService, $startedAt, $limit);
            $hasFailures = $record->failed->value > 0;

            if ($format === 'json') {
                $this->outputRecurringJsonStruct($record);
            } else {
                $this->displayProcessingStart($limit);
                $this->displayRecurringResults($record);
                $this->displayRecurringErrorsIfVerbose($verbose, $record->errors);
            }
        } else {
            $record = $this->processFull($uniqueService, $recurringService, $startedAt, $limit);
            $hasFailures = $record->unique_failed->value > 0 || $record->recurring_failed->value > 0;

            if ($format === 'json') {
                $this->outputFullJsonStruct($record);
            } else {
                $this->displayProcessingStart($limit);
                $this->displayFullResults($record);
                $this->displayFullErrorsIfVerbose($verbose, $record);
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

    private function validateOptions(): ?ExitCode
    {
        $uniqueOnly = $this->hasOption('unique-only');
        $recurringOnly = $this->hasOption('recurring-only');

        if ($uniqueOnly && $recurringOnly) {
            $this->error('Cannot use both --unique-only and --recurring-only');

            return ExitCode::INVALID_ARGUMENT;
        }

        $limit = $this->option('limit');

        if ($limit !== null && (int) $limit <= 0) {
            $this->error('Limit must be a positive integer');

            return ExitCode::INVALID_ARGUMENT;
        }

        $format = $this->option('format');

        if ($format !== null && ! in_array($format, ['text', 'json'], true)) {
            $this->error('Format must be "text" or "json"');

            return ExitCode::INVALID_ARGUMENT;
        }

        return null;
    }

    private function getValidatedLimit(): ?int
    {
        $limit = $this->option('limit');

        return $limit !== null ? (int) $limit : null;
    }

    private function displayProcessingStart(?int $limit): void
    {
        $this->info('Processing tasks...');

        if ($limit !== null) {
            $this->info("Limit: {$limit} tasks");
        }
    }

    // ==================== UNIQUE TASKS ====================

    private function processUniqueOnly(
        UniqueTaskServiceInterface $service,
        Iso8601DateTimeVO $startedAt,
        ?int $limit
    ): UniqueBatchResultRecord {
        $result = $service->process($limit);

        return new UniqueBatchResultRecord(
            started_at: $startedAt,
            success: $result->success,
            failed: $result->failed,
            results: $result->results ?? new UniqueResultCollection,
            errors: $result->errors ?? new TaskErrorRecordCollection,
        );
    }

    private function displayUniqueResults(UniqueBatchResultRecord $record): void
    {
        $total = $record->success->value + $record->failed->value;

        $this->newLine();
        $this->info('<fg=cyan>=== Unique Batch Results ===</>');
        $this->info(sprintf('  Success: %d', $record->success->value));
        $this->info(sprintf('  Failed: %d', $record->failed->value));
        $this->info(sprintf('  Total: %d', $total));
    }

    private function outputUniqueJsonStruct(UniqueBatchResultRecord $record): void
    {
        $endedAt = new Iso8601DateTimeVO;
        $total = $record->success->value + $record->failed->value;
        $duration = $this->getDurationMilliseconds($record->started_at);

        $errorsCollection = new TaskErrorStructCollection;
        foreach ($record->errors as $error) {
            $errorsCollection->add(new TaskErrorStruct(
                alias: $error->alias,
                fqcn: $error->fqcn,
                error: $error->error,
                context: sprintf('Unique task execution failed after %d attempt(s)', $error->context ?? 'multiple'),
            ));
        }

        $struct = new UniqueBatchResultStruct(
            started_at: $record->started_at->value,
            ended_at: $endedAt->value,
            duration_ms: $duration,
            success: $record->success->value,
            failed: $record->failed->value,
            total: $total,
            errors: $errorsCollection,
            has_failures: $record->failed->value > 0,
        );

        $this->line((string) $struct);
    }

    private function displayUniqueErrorsIfVerbose(bool $verbose, TaskErrorRecordCollection $errors): void
    {
        if (! $verbose || $errors->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->info('<fg=red>=== Failed Unique Tasks ===</>');
        foreach ($errors as $error) {
            $displayName = $error->alias ?? $error->identifier;
            $this->info(sprintf('    ❌ %s: %s', $displayName, $error->error));
        }
    }

    // ==================== RECURRING TASKS ====================

    private function processRecurringOnly(
        RecurringTaskServiceInterface $service,
        Iso8601DateTimeVO $startedAt,
        ?int $limit
    ): RecurringBatchResultRecord {
        $result = $service->process($limit);

        return new RecurringBatchResultRecord(
            started_at: $startedAt,
            success: $result->success,
            failed: $result->failed,
            results: $result->results ?? new RecurringResultCollection,
            errors: $result->errors ?? new TaskErrorRecordCollection,
        );
    }

    private function displayRecurringResults(RecurringBatchResultRecord $record): void
    {
        $total = $record->success->value + $record->failed->value;

        $this->newLine();
        $this->info('<fg=cyan>=== Recurring Batch Results ===</>');
        $this->info(sprintf('  Success: %d', $record->success->value));
        $this->info(sprintf('  Failed: %d', $record->failed->value));
        $this->info(sprintf('  Total: %d', $total));
    }

    private function outputRecurringJsonStruct(RecurringBatchResultRecord $record): void
    {
        $endedAt = new Iso8601DateTimeVO;
        $total = $record->success->value + $record->failed->value;
        $duration = $this->getDurationMilliseconds($record->started_at);

        $errorsCollection = new TaskErrorStructCollection;
        foreach ($record->errors as $error) {
            $errorsCollection->add(new TaskErrorStruct(
                alias: $error->alias,
                fqcn: $error->fqcn,
                error: $error->error,
                context: 'Recurring task execution failed',
            ));
        }

        $struct = new RecurringBatchResultStruct(
            started_at: $record->started_at->value,
            ended_at: $endedAt->value,
            duration_ms: $duration,
            success: $record->success->value,
            failed: $record->failed->value,
            total: $total,
            errors: $errorsCollection,
            has_failures: $record->failed->value > 0,
        );

        $this->line((string) $struct);
    }

    private function displayRecurringErrorsIfVerbose(bool $verbose, TaskErrorRecordCollection $errors): void
    {
        if (! $verbose || $errors->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->info('<fg=red>=== Failed Recurring Tasks ===</>');
        foreach ($errors as $error) {
            $displayName = $error->alias ?? $error->identifier;
            $this->info(sprintf('    ❌ %s: %s', $displayName, $error->error));
        }
    }

    // ==================== FULL (BOTH) ====================

    private function processFull(
        UniqueTaskServiceInterface $uniqueService,
        RecurringTaskServiceInterface $recurringService,
        Iso8601DateTimeVO $startedAt,
        ?int $limit
    ): BatchResultRecord {
        $uniqueResult = $uniqueService->process($limit);
        $recurringResult = $recurringService->process($limit);

        return new BatchResultRecord(
            started_at: $startedAt,
            unique_success: $uniqueResult->success,
            unique_failed: $uniqueResult->failed,
            recurring_success: $recurringResult->success,
            recurring_failed: $recurringResult->failed,
            unique_errors: $uniqueResult->errors ?? new TaskErrorRecordCollection,
            recurring_errors: $recurringResult->errors ?? new TaskErrorRecordCollection,
        );
    }

    private function displayFullResults(BatchResultRecord $record): void
    {
        $this->newLine();
        $this->info('<fg=cyan>=== Batch Results ===</>');
        $this->info(sprintf('  Unique:    ✅ %d, ❌ %d',
            $record->unique_success->value,
            $record->unique_failed->value
        ));
        $this->info(sprintf('  Recurring: ✅ %d, ❌ %d',
            $record->recurring_success->value,
            $record->recurring_failed->value
        ));

        $totalSuccess = $record->unique_success->value + $record->recurring_success->value;
        $totalFailed = $record->unique_failed->value + $record->recurring_failed->value;
        $totalProcessed = $totalSuccess + $totalFailed;
        $hasFailures = $record->unique_failed->value > 0 || $record->recurring_failed->value > 0;

        $this->info(sprintf('  Total:     ✅ %d, ❌ %d, 📦 %d',
            $totalSuccess,
            $totalFailed,
            $totalProcessed
        ));
        $this->info(sprintf('  Has failures: %s', $hasFailures ? 'Yes' : 'No'));
    }

    private function outputFullJsonStruct(BatchResultRecord $record): void
    {
        $endedAt = new Iso8601DateTimeVO;
        $duration = $this->getDurationMilliseconds($record->started_at);

        $uniqueErrors = new TaskErrorStructCollection;
        foreach ($record->unique_errors as $error) {
            $uniqueErrors->add(new TaskErrorStruct(
                alias: $error->alias,
                fqcn: $error->fqcn,
                error: $error->error,
                context: sprintf('Unique task failed (attempts: %s)', $error->context ?? 'unknown'),
            ));
        }

        $recurringErrors = new TaskErrorStructCollection;
        foreach ($record->recurring_errors as $error) {
            $recurringErrors->add(new TaskErrorStruct(
                alias: $error->alias,
                fqcn: $error->fqcn,
                error: $error->error,
                context: 'Recurring task failed',
            ));
        }

        $totalSuccess = $record->unique_success->value + $record->recurring_success->value;
        $totalFailed = $record->unique_failed->value + $record->recurring_failed->value;
        $totalProcessed = $totalSuccess + $totalFailed;
        $hasFailures = $record->unique_failed->value > 0 || $record->recurring_failed->value > 0;

        $struct = new BatchResultStruct(
            started_at: $record->started_at->value,
            ended_at: $endedAt->value,
            duration_ms: $duration,
            success: $totalSuccess,
            failed: $totalFailed,
            total: $totalProcessed,
            errors: $this->mergeErrors($uniqueErrors, $recurringErrors),
            has_failures: $hasFailures,
        );

        $this->line((string) $struct);
    }

    private function mergeErrors(
        TaskErrorStructCollection $uniqueErrors,
        TaskErrorStructCollection $recurringErrors
    ): TaskErrorStructCollection {
        $merged = new TaskErrorStructCollection;

        foreach ($uniqueErrors as $error) {
            $merged->add($error);
        }

        foreach ($recurringErrors as $error) {
            $merged->add($error);
        }

        return $merged;
    }

    private function displayFullErrorsIfVerbose(bool $verbose, BatchResultRecord $record): void
    {
        if (! $verbose) {
            return;
        }

        $hasUniqueErrors = ! $record->unique_errors->isEmpty();
        $hasRecurringErrors = ! $record->recurring_errors->isEmpty();

        if (! $hasUniqueErrors && ! $hasRecurringErrors) {
            return;
        }

        $this->newLine();
        $this->info('<fg=red>=== Failed Tasks ===</>');

        if ($hasUniqueErrors) {
            $this->info('  Unique tasks:');
            foreach ($record->unique_errors as $error) {
                $displayName = $error->alias ?? $error->identifier;
                $this->info(sprintf('    ❌ %s: %s', $displayName, $error->error));
            }
        }

        if ($hasRecurringErrors) {
            $this->info('  Recurring tasks:');
            foreach ($record->recurring_errors as $error) {
                $displayName = $error->alias ?? $error->identifier;
                $this->info(sprintf('    ❌ %s: %s', $displayName, $error->error));
            }
        }
    }

    private function getDurationMilliseconds(Iso8601DateTimeVO $start): int
    {
        $startTimestamp = $start->toDateTime()->getTimestamp();
        $endTimestamp = (new Iso8601DateTimeVO)->toDateTime()->getTimestamp();

        return (int) (($endTimestamp - $startTimestamp) * 1000);
    }
}
