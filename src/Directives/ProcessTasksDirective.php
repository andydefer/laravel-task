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
use RuntimeException;

/**
 * Console directive for processing pending tasks in batch mode.
 *
 * Allows processing of unique and/or recurring tasks with support for
 * various output formats and filtering options. Provides comprehensive
 * logging and error reporting for batch task execution.
 */
final class ProcessTasksDirective extends AbstractDirective
{
    /**
     * Returns the command signature with available options.
     *
     * @return string The command signature
     */
    public function getSignature(): string
    {
        return 'process-tasks {limit=?} {format=text} {--unique-only} {--recurring-only} {--verbose}';
    }

    /**
     * Returns the command description.
     *
     * @return string The command description
     */
    public function getDescription(): string
    {
        return 'Process all pending tasks in a single batch (no polling, no waiting)';
    }

    /**
     * Returns the command aliases.
     *
     * @return StringTypedCollection Collection of command aliases
     */
    public function getAliases(): StringTypedCollection
    {
        $aliases = new StringTypedCollection;
        $aliases->add('task-process');
        $aliases->add('tasks-process');

        return $aliases;
    }

    /**
     * Executes the task processing directive.
     *
     * @return ExitCode The exit code indicating success or failure
     *
     * @throws RuntimeException When Laravel container is not available
     */
    public function execute(): ExitCode
    {
        $app = $this->getContainer();

        if ($app === null) {
            throw new RuntimeException('Laravel container is not available');
        }

        $console = $app->make(Console::class);

        $validationResult = $this->validateOptions($console);
        if ($validationResult !== null) {
            return $validationResult;
        }

        $uniqueOnly = $this->hasFlag('unique-only');
        $recurringOnly = $this->hasFlag('recurring-only');
        $verbose = $this->hasFlag('verbose');
        $limit = $this->getValidatedLimit();
        $format = $this->argument('format') ?? 'text';

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

    /**
     * Retrieves the unique task service from the container.
     *
     * @return UniqueTaskServiceInterface The unique task service
     *
     * @throws RuntimeException When Laravel container is not available
     */
    private function getUniqueTaskService(): UniqueTaskServiceInterface
    {
        $laravel = $this->getContainer();

        if ($laravel === null) {
            throw new RuntimeException('Laravel container is not available. Task processing requires Laravel.');
        }

        return $laravel->make(UniqueTaskServiceInterface::class);
    }

    /**
     * Retrieves the recurring task service from the container.
     *
     * @return RecurringTaskServiceInterface The recurring task service
     *
     * @throws RuntimeException When Laravel container is not available
     */
    private function getRecurringTaskService(): RecurringTaskServiceInterface
    {
        $laravel = $this->getContainer();

        if ($laravel === null) {
            throw new RuntimeException('Laravel container is not available. Task processing requires Laravel.');
        }

        return $laravel->make(RecurringTaskServiceInterface::class);
    }

    /**
     * Validates the command options.
     *
     * @param  Console  $console  The console instance for error output
     * @return ExitCode|null Exit code if validation fails, null otherwise
     */
    private function validateOptions(Console $console): ?ExitCode
    {
        $uniqueOnly = $this->hasFlag('unique-only');
        $recurringOnly = $this->hasFlag('recurring-only');

        if ($uniqueOnly && $recurringOnly) {
            $console->error('Cannot use both --unique-only and --recurring-only');

            return ExitCode::INVALID_ARGUMENT;
        }

        $limit = $this->argument('limit');

        if ($limit !== null && (int) $limit <= 0) {
            $console->error('Limit must be a positive integer');

            return ExitCode::INVALID_ARGUMENT;
        }

        $format = $this->argument('format');

        if ($format !== null && ! in_array($format, ['text', 'json'], true)) {
            $console->error('Format must be "text" or "json"');

            return ExitCode::INVALID_ARGUMENT;
        }

        return null;
    }

    /**
     * Returns the validated limit value.
     *
     * @return int|null The limit or null if not set
     */
    private function getValidatedLimit(): ?int
    {
        $limit = $this->argument('limit');

        return $limit !== null ? (int) $limit : null;
    }

    /**
     * Displays the processing start message.
     *
     * @param  Console  $console  The console instance
     * @param  int|null  $limit  The task limit if set
     */
    private function displayProcessingStart(Console $console, ?int $limit): void
    {
        $console->info('Processing tasks...');

        if ($limit !== null) {
            $console->info('Limit: '.$limit.' tasks');
        }
    }

    // ==================== UNIQUE TASKS ====================

    /**
     * Processes only unique tasks.
     *
     * @param  UniqueTaskServiceInterface  $service  The unique task service
     * @param  int|null  $limit  Optional limit for processing
     * @return ProcessResultRecord The processing result
     */
    private function processUniqueOnly(
        UniqueTaskServiceInterface $service,
        ?int $limit
    ): ProcessResultRecord {
        if ($limit !== null) {
            return $service->process(new LimitVO($limit));
        }

        return $service->process();
    }

    /**
     * Displays unique task processing results.
     *
     * @param  Console  $console  The console instance
     * @param  ProcessResultRecord  $result  The processing result
     */
    private function displayUniqueResults(Console $console, ProcessResultRecord $result): void
    {
        $total = $result->success->getValue() + $result->failed->getValue();

        $console->line();
        $console->title('=== Unique Batch Results ===');
        $console->info('  Success: '.$result->success->getValue());
        $console->error('  Failed: '.$result->failed->getValue());
        $console->info('  Total: '.$total);
    }

    /**
     * Outputs unique task results in JSON format.
     *
     * @param  Console  $console  The console instance
     * @param  ProcessResultRecord  $result  The processing result
     */
    private function outputUniqueJson(Console $console, ProcessResultRecord $result): void
    {
        $endedAt = new Iso8601DateTimeVO;
        $duration = $this->calculateDurationMilliseconds($result->started_at);
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

    /**
     * Processes only recurring tasks.
     *
     * @param  RecurringTaskServiceInterface  $service  The recurring task service
     * @param  int|null  $limit  Optional limit for processing
     * @return ProcessResultRecord The processing result
     */
    private function processRecurringOnly(
        RecurringTaskServiceInterface $service,
        ?int $limit
    ): ProcessResultRecord {
        if ($limit !== null) {
            return $service->process(new LimitVO($limit));
        }

        return $service->process();
    }

    /**
     * Displays recurring task processing results.
     *
     * @param  Console  $console  The console instance
     * @param  ProcessResultRecord  $result  The processing result
     */
    private function displayRecurringResults(Console $console, ProcessResultRecord $result): void
    {
        $total = $result->success->getValue() + $result->failed->getValue();

        $console->line();
        $console->title('=== Recurring Batch Results ===');
        $console->info('  Success: '.$result->success->getValue());
        $console->error('  Failed: '.$result->failed->getValue());
        $console->info('  Total: '.$total);
    }

    /**
     * Outputs recurring task results in JSON format.
     *
     * @param  Console  $console  The console instance
     * @param  ProcessResultRecord  $result  The processing result
     */
    private function outputRecurringJson(Console $console, ProcessResultRecord $result): void
    {
        $endedAt = new Iso8601DateTimeVO;
        $duration = $this->calculateDurationMilliseconds($result->started_at);
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

    /**
     * Displays combined results for both task types.
     *
     * @param  Console  $console  The console instance
     * @param  ProcessResultRecord  $uniqueResult  The unique task result
     * @param  ProcessResultRecord  $recurringResult  The recurring task result
     */
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

    /**
     * Outputs combined results in JSON format.
     *
     * @param  Console  $console  The console instance
     * @param  ProcessResultRecord  $uniqueResult  The unique task result
     * @param  ProcessResultRecord  $recurringResult  The recurring task result
     */
    private function outputFullJson(
        Console $console,
        ProcessResultRecord $uniqueResult,
        ProcessResultRecord $recurringResult
    ): void {
        $endedAt = new Iso8601DateTimeVO;
        $duration = $this->calculateDurationMilliseconds($uniqueResult->started_at);

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

        $allErrors = $this->mergeErrorCollections($uniqueResult->errors, $recurringResult->errors);

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

    /**
     * Displays errors for a single task type when verbose mode is enabled.
     *
     * @param  Console  $console  The console instance
     * @param  bool  $verbose  Whether verbose mode is enabled
     * @param  iterable  $errors  The error collection
     * @param  string  $type  The task type label
     */
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

    /**
     * Displays errors for both task types when verbose mode is enabled.
     *
     * @param  Console  $console  The console instance
     * @param  bool  $verbose  Whether verbose mode is enabled
     * @param  ProcessResultRecord  $uniqueResult  The unique task result
     * @param  ProcessResultRecord  $recurringResult  The recurring task result
     */
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

    /**
     * Merges two error collections into one.
     *
     * @param  TaskErrorRecordCollection  $uniqueErrors  The unique task errors
     * @param  TaskErrorRecordCollection  $recurringErrors  The recurring task errors
     * @return TaskErrorRecordCollection The merged error collection
     */
    private function mergeErrorCollections(
        TaskErrorRecordCollection $uniqueErrors,
        TaskErrorRecordCollection $recurringErrors
    ): TaskErrorRecordCollection {
        $allErrors = new TaskErrorRecordCollection;

        foreach ($uniqueErrors as $error) {
            $allErrors->add($error);
        }

        foreach ($recurringErrors as $error) {
            $allErrors->add($error);
        }

        return $allErrors;
    }

    /**
     * Calculates duration in milliseconds between start and current time.
     *
     * @param  Iso8601DateTimeVO  $start  The start timestamp
     * @return int The duration in milliseconds
     */
    private function calculateDurationMilliseconds(Iso8601DateTimeVO $start): int
    {
        $startTimestamp = $start->toCarbon()->getTimestamp();
        $endTimestamp = (new Iso8601DateTimeVO)->toCarbon()->getTimestamp();

        return (int) (($endTimestamp - $startTimestamp) * 1000);
    }
}
