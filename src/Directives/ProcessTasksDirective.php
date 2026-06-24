<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\FullBatchJsonResultRecord;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\TaskExecutionJsonResultRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\StyledTextVO;

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

        $hasFailures = false;

        if ($uniqueOnly) {
            $result = $this->processUniqueOnly($uniqueService, $limit);
            $hasFailures = $result->failed->isPositive();

            if ($format === 'json') {
                $this->outputUniqueJson($result);
            } else {
                $this->displayProcessingStart($limit);
                $this->displayUniqueResults($result);
                $this->displayErrorsIfVerbose($verbose, $result->errors, 'Unique');
            }
        } elseif ($recurringOnly) {
            $result = $this->processRecurringOnly($recurringService, $limit);
            $hasFailures = $result->failed->isPositive();

            if ($format === 'json') {
                $this->outputRecurringJson($result);
            } else {
                $this->displayProcessingStart($limit);
                $this->displayRecurringResults($result);
                $this->displayErrorsIfVerbose($verbose, $result->errors, 'Recurring');
            }
        } else {
            $uniqueResult = $this->processUniqueOnly($uniqueService, $limit);
            $recurringResult = $this->processRecurringOnly($recurringService, $limit);
            $hasFailures = $uniqueResult->failed->isPositive() || $recurringResult->failed->isPositive();

            if ($format === 'json') {
                $this->outputFullJson($uniqueResult, $recurringResult);
            } else {
                $this->displayProcessingStart($limit);
                $this->displayFullResults($uniqueResult, $recurringResult);
                $this->displayFullErrorsIfVerbose($verbose, $uniqueResult, $recurringResult);
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
        $text = StyledTextVO::empty()
            ->append('Processing tasks...')
            ->newLine();

        if ($limit !== null) {
            $text = $text->append('Limit: ')
                ->yellow()
                ->append((string) $limit)
                ->reset()
                ->append(' tasks');
        }

        $this->info($text->value);
    }

    // ==================== UNIQUE TASKS ====================

    private function processUniqueOnly(
        UniqueTaskServiceInterface $service,
        ?int $limit
    ): ProcessResultRecord {
        return $service->process($limit);
    }

    private function displayUniqueResults(ProcessResultRecord $result): void
    {
        $total = $result->success->getValue() + $result->failed->getValue();

        $text = StyledTextVO::empty()
            ->newLine()
            ->cyan()->append('=== Unique Batch Results ===')->reset()
            ->newLine()
            ->append('  Success: ')
            ->cyan()->append((string) $result->success->getValue())->reset()
            ->newLine()
            ->append('  Failed: ')
            ->red()->append((string) $result->failed->getValue())->reset()
            ->newLine()
            ->append('  Total: ')
            ->yellow()->append((string) $total)->reset();

        $this->info($text->value);
    }

    private function outputUniqueJson(ProcessResultRecord $result): void
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

        $this->line((string) $jsonResult);
    }

    // ==================== RECURRING TASKS ====================

    private function processRecurringOnly(
        RecurringTaskServiceInterface $service,
        ?int $limit
    ): ProcessResultRecord {
        return $service->process($limit);
    }

    private function displayRecurringResults(ProcessResultRecord $result): void
    {
        $total = $result->success->getValue() + $result->failed->getValue();

        $text = StyledTextVO::empty()
            ->newLine()
            ->cyan()->append('=== Recurring Batch Results ===')->reset()
            ->newLine()
            ->append('  Success: ')
            ->cyan()->append((string) $result->success->getValue())->reset()
            ->newLine()
            ->append('  Failed: ')
            ->red()->append((string) $result->failed->getValue())->reset()
            ->newLine()
            ->append('  Total: ')
            ->yellow()->append((string) $total)->reset();

        $this->info($text->value);
    }

    private function outputRecurringJson(ProcessResultRecord $result): void
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

        $this->line((string) $jsonResult);
    }

    // ==================== FULL (BOTH) ====================

    private function displayFullResults(
        ProcessResultRecord $uniqueResult,
        ProcessResultRecord $recurringResult
    ): void {
        $totalSuccess = $uniqueResult->success->getValue() + $recurringResult->success->getValue();
        $totalFailed = $uniqueResult->failed->getValue() + $recurringResult->failed->getValue();
        $totalProcessed = $totalSuccess + $totalFailed;
        $hasFailures = $uniqueResult->failed->isPositive() || $recurringResult->failed->isPositive();

        $text = StyledTextVO::empty()
            ->newLine()
            ->cyan()->append('=== Batch Results ===')->reset()
            ->newLine()
            ->append('  Unique:    ')
            ->green()->append('✅ ')->append((string) $uniqueResult->success->getValue())->reset()
            ->append(', ')
            ->red()->append('❌ ')->append((string) $uniqueResult->failed->getValue())->reset()
            ->newLine()
            ->append('  Recurring: ')
            ->green()->append('✅ ')->append((string) $recurringResult->success->getValue())->reset()
            ->append(', ')
            ->red()->append('❌ ')->append((string) $recurringResult->failed->getValue())->reset()
            ->newLine()
            ->append('  Total:     ')
            ->green()->append('✅ ')->append((string) $totalSuccess)->reset()
            ->append(', ')
            ->red()->append('❌ ')->append((string) $totalFailed)->reset()
            ->append(', ')
            ->blue()->append('📦 ')->append((string) $totalProcessed)->reset()
            ->newLine()
            ->append('  Has failures: ')
            ->append($hasFailures ? 'Yes' : 'No');

        $this->info($text->value);
    }

    private function outputFullJson(
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

        $this->line((string) $fullResult);
    }

    // ==================== ERRORS ====================

    private function displayErrorsIfVerbose(
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

        $text = StyledTextVO::empty()
            ->newLine()
            ->red()->append('=== Failed ')->append($type)->append(' Tasks ===')->reset();

        foreach ($errorsArray as $error) {
            $displayName = $error->alias ?? $error->identifier;
            $text = $text
                ->newLine()
                ->append('    ')
                ->red()->append('❌ ')->append($displayName)->append(': ')->append($error->error)->reset();
        }

        $this->info($text->value);
    }

    private function displayFullErrorsIfVerbose(
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

        $text = StyledTextVO::empty()
            ->newLine()
            ->red()->append('=== Failed Tasks ===')->reset();

        if ($hasUniqueErrors) {
            $text = $text->newLine()->append('  Unique tasks:');
            foreach ($uniqueResult->errors as $error) {
                $displayName = $error->alias ?? $error->identifier;
                $text = $text
                    ->newLine()
                    ->append('    ')
                    ->red()->append('❌ ')->append($displayName)->append(': ')->append($error->error)->reset();
            }
        }

        if ($hasRecurringErrors) {
            $text = $text->newLine()->append('  Recurring tasks:');
            foreach ($recurringResult->errors as $error) {
                $displayName = $error->alias ?? $error->identifier;
                $text = $text
                    ->newLine()
                    ->append('    ')
                    ->red()->append('❌ ')->append($displayName)->append(': ')->append($error->error)->reset();
            }
        }

        $this->info($text->value);
    }

    private function getDurationMilliseconds(Iso8601DateTimeVO $start): int
    {
        $startTimestamp = $start->toDateTime()->getTimestamp();
        $endTimestamp = (new Iso8601DateTimeVO)->toDateTime()->getTimestamp();

        return (int) (($endTimestamp - $startTimestamp) * 1000);
    }
}
