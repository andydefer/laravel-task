<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Services\TaskBatchService;
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
        return 'process-tasks {--unique-only : Process only unique tasks} {--recurring-only : Process only recurring tasks} {--verbose : Show detailed task results} {--limit= : Maximum number of tasks to process}';
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
        $aliases = new StringTypedCollection();
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

        $batch = $this->getBatchService();

        $record = $this->executeBatchProcessing($batch, $uniqueOnly, $recurringOnly, $limit);

        $this->displayResultsSummary($record);
        $this->displayErrorsIfVerbose($verbose, $record);

        $hasFailures = $record->unique_failed->value > 0 || $record->recurring_failed->value > 0;

        return $hasFailures ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    private function getBatchService(): TaskBatchService
    {
        $laravel = $this->getLaravel();

        if ($laravel === null) {
            throw new \RuntimeException('Laravel container is not available. Task processing requires Laravel.');
        }

        return $laravel->make(TaskBatchService::class);
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

    private function executeBatchProcessing(TaskBatchService $batch, bool $uniqueOnly, bool $recurringOnly, ?int $limit): BatchResultRecord
    {
        if ($uniqueOnly) {
            return $batch->processUniqueOnly($limit);
        }

        if ($recurringOnly) {
            return $batch->processRecurringOnly($limit);
        }

        return $batch->process($limit);
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
        if (!$verbose) {
            return;
        }

        $hasUniqueErrors = !$record->unique_errors->isEmpty();
        $hasRecurringErrors = !$record->recurring_errors->isEmpty();

        if (!$hasUniqueErrors && !$hasRecurringErrors) {
            return;
        }

        $this->newLine();
        $this->info('<fg=red>=== Failed Tasks ===</>');

        if ($hasUniqueErrors) {
            $this->info('  Unique tasks:');
            foreach ($record->unique_errors as $error) {
                $this->info(sprintf('    ❌ %s: %s', $error->task_id->value, $error->details));
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
        $end = (new Iso8601DateTimeVO())->toDateTime()->getTimestamp();

        return (int) (($end - $start) * 1000);
    }
}
