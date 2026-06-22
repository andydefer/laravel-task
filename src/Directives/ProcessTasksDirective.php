<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\RecurringTaskErrorCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

/**
 * Console directive for processing tasks in a single batch operation.
 *
 * @example ./vendor/bin/directive process-tasks
 * @example ./vendor/bin/directive process-tasks --unique-only --limit=10
 * @example ./vendor/bin/directive process-tasks --recurring-only --verbose
 */
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
        return 'process-tasks {--unique-only} {--recurring-only} {--verbose} {--limit=}';
    }

    public function shouldBootLaravel(): bool
    {
        return true;
    }

    public function getDescription(): string
    {
        return 'Process all pending tasks in a single batch (no polling, no waiting)';
    }

    public function getAliases(): StringTypedCollection
    {
        $aliases = new StringTypedCollection;
        $aliases->add('task:process');
        $aliases->add('tasks:process');

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

        $this->displayProcessingStart($limit);

        $uniqueService = $this->getUniqueTaskService();
        $recurringService = $this->getRecurringTaskService();

        $record = $this->executeBatchProcessing(
            $uniqueService,
            $recurringService,
            $uniqueOnly,
            $recurringOnly,
            $limit
        );

        $this->displayResultsSummary($record);
        $this->displayErrorsIfVerbose($verbose, $record);

        $hasFailures = $record->unique_failed->value > 0 || $record->recurring_failed->value > 0;

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

    private function executeBatchProcessing(
        UniqueTaskServiceInterface $uniqueService,
        RecurringTaskServiceInterface $recurringService,
        bool $uniqueOnly,
        bool $recurringOnly,
        ?int $limit
    ): BatchResultRecord {
        $startedAt = new Iso8601DateTimeVO;

        if ($uniqueOnly) {
            return $this->processUniqueOnly($uniqueService, $startedAt, $limit);
        }

        if ($recurringOnly) {
            return $this->processRecurringOnly($recurringService, $startedAt, $limit);
        }

        return $this->processFull($uniqueService, $recurringService, $startedAt, $limit);
    }

    private function processUniqueOnly(
        UniqueTaskServiceInterface $service,
        Iso8601DateTimeVO $startedAt,
        ?int $limit
    ): BatchResultRecord {
        $results = $service->process($limit);

        return new BatchResultRecord(
            started_at: $startedAt,
            unique_success: new CounterVO($results['success']),
            unique_failed: new CounterVO($results['failed']),
            recurring_success: new CounterVO(0),
            recurring_failed: new CounterVO(0),
            unique_results: new UniqueResultCollection,
            recurring_results: new RecurringResultCollection,
            unique_errors: new TaskErrorCollection,
            recurring_errors: new RecurringTaskErrorCollection,
        );
    }

    private function processRecurringOnly(
        RecurringTaskServiceInterface $service,
        Iso8601DateTimeVO $startedAt,
        ?int $limit
    ): BatchResultRecord {
        $results = $service->process($limit);

        return new BatchResultRecord(
            started_at: $startedAt,
            unique_success: new CounterVO(0),
            unique_failed: new CounterVO(0),
            recurring_success: new CounterVO($results['success']),
            recurring_failed: new CounterVO($results['failed']),
            unique_results: new UniqueResultCollection,
            recurring_results: new RecurringResultCollection,
            unique_errors: new TaskErrorCollection,
            recurring_errors: new RecurringTaskErrorCollection,
        );
    }

    private function processFull(
        UniqueTaskServiceInterface $uniqueService,
        RecurringTaskServiceInterface $recurringService,
        Iso8601DateTimeVO $startedAt,
        ?int $limit
    ): BatchResultRecord {
        $uniqueResults = $uniqueService->process($limit);
        $recurringResults = $recurringService->process($limit);

        return new BatchResultRecord(
            started_at: $startedAt,
            unique_success: new CounterVO($uniqueResults['success']),
            unique_failed: new CounterVO($uniqueResults['failed']),
            recurring_success: new CounterVO($recurringResults['success']),
            recurring_failed: new CounterVO($recurringResults['failed']),
            unique_results: new UniqueResultCollection,
            recurring_results: new RecurringResultCollection,
            unique_errors: new TaskErrorCollection,
            recurring_errors: new RecurringTaskErrorCollection,
        );
    }

    private function displayResultsSummary(BatchResultRecord $record): void
    {
        $totalProcessed = $record->unique_success->value + $record->unique_failed->value
            + $record->recurring_success->value + $record->recurring_failed->value;

        $this->newLine();
        $this->info('<fg=cyan>=== Batch Results ===</>');

        $this->displayTaskTypeSummary('Unique tasks', $record->unique_success->value, $record->unique_failed->value);
        $this->displayTaskTypeSummary('Recurring tasks', $record->recurring_success->value, $record->recurring_failed->value);

        $this->info(sprintf(
            '  Total:          %d tasks in %d ms',
            $totalProcessed,
            $this->getDurationMilliseconds($record)
        ));
    }

    private function displayTaskTypeSummary(string $taskTypeLabel, int $successCount, int $failureCount): void
    {
        $processedCount = $successCount + $failureCount;

        $this->info(sprintf(
            '  %s: %d processed (✅ %d, ❌ %d)',
            $taskTypeLabel,
            $processedCount,
            $successCount,
            $failureCount
        ));
    }

    private function displayErrorsIfVerbose(bool $verbose, BatchResultRecord $record): void
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
                $this->info(sprintf('    ❌ %s: %s', $error->identifier, $error->error));
            }
        }

        if ($hasRecurringErrors) {
            $this->info('  Recurring tasks:');
            foreach ($record->recurring_errors as $error) {
                $this->info(sprintf('    ❌ %s: %s', $error->signature->value, $error->details));
            }
        }
    }

    private function getDurationMilliseconds(BatchResultRecord $record): int
    {
        $start = $record->started_at->toDateTime()->getTimestamp();
        $end = (new Iso8601DateTimeVO)->toDateTime()->getTimestamp();

        return (int) (($end - $start) * 1000);
    }
}
