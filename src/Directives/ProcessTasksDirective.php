<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\ValueObjects\Iso8601DateTime;

/**
 * Console directive for processing tasks in a single batch operation.
 *
 * This command-line directive executes pending tasks without polling or waiting,
 * providing real-time feedback and detailed result summaries. Supports filtering
 * by task type (unique/recurring) and configurable processing limits.
 *
 * @example php console process-tasks
 * @example php console process-tasks --unique-only --limit=10
 * @example php console process-tasks --recurring-only --verbose
 */
final class ProcessTasksDirective extends AbstractDirective
{
    public function __construct(
        DirectiveContext $context,
        DirectiveInteractionService $interaction,
        private readonly TaskBatchService $batch,
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

        $record = $this->executeBatchProcessing($uniqueOnly, $recurringOnly, $limit);

        $this->displayResultsSummary($record);
        $this->displayErrorsIfVerbose($verbose, $record);

        $hasFailures = $record->uniqueFailed > 0 || $record->recurringFailed > 0;

        return $hasFailures ? ExitCode::FAILURE : ExitCode::SUCCESS;
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

    private function executeBatchProcessing(bool $uniqueOnly, bool $recurringOnly, ?int $limit): BatchResultRecord
    {
        if ($uniqueOnly) {
            return $this->batch->processUniqueOnly($limit);
        }

        if ($recurringOnly) {
            return $this->batch->processRecurringOnly($limit);
        }

        return $this->batch->process($limit);
    }

    private function displayResultsSummary(BatchResultRecord $record): void
    {
        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        $this->newLine();
        $this->info('<fg=cyan>=== Batch Results ===</>');

        $this->displayTaskTypeSummary('Unique tasks', $record->uniqueSuccess, $record->uniqueFailed);
        $this->displayTaskTypeSummary('Recurring tasks', $record->recurringSuccess, $record->recurringFailed);

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
        if (!$verbose || $record->errors->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->info('<fg=red>=== Failed Tasks ===</>');

        foreach ($record->errors as $error) {
            $this->info(sprintf('  ❌ %s: %s', $error->taskId, $error->error));
        }
    }

    private function getDurationMilliseconds(BatchResultRecord $record): int
    {
        $start = $record->startedAt->toDateTime()->getTimestamp();
        $end = (new Iso8601DateTime())->toDateTime()->getTimestamp();

        return (int) (($end - $start) * 1000);
    }
}
