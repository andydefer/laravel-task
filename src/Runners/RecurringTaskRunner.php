<?php

declare(strict_types=1);

namespace AndyDefer\Task\Runners;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Contracts\Loggers\RecurringTaskLoggerInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Runners\RecurringTaskRunnerInterface;
use AndyDefer\Task\Contracts\Validators\RecurringTaskValidatorInterface;
use AndyDefer\Task\Records\ExecutionResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Runner for recurring tasks.
 *
 * Handles the execution of recurring tasks including validation,
 * logging, and state management.
 */
final class RecurringTaskRunner implements RecurringTaskRunnerInterface
{
    /**
     * Constructor for the recurring task runner.
     *
     * @param  RecurringTaskValidatorInterface  $validator  The task validator
     * @param  RecurringTaskLoggerInterface  $logger  The task logger
     * @param  HydrationService  $hydration  The hydration service
     * @param  Application  $app  The application container
     * @param  RecurringTaskRepositoryInterface  $repository  The task repository
     */
    public function __construct(
        private readonly RecurringTaskValidatorInterface $validator,
        private readonly RecurringTaskLoggerInterface $logger,
        private readonly HydrationService $hydration,
        private readonly Application $app,
        private readonly RecurringTaskRepositoryInterface $repository,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function run(RecurringTaskRecord $record): ExecutionResultRecord
    {
        $startTime = new Iso8601DateTimeVO;

        $validationResult = $this->validateTask($record);
        if ($validationResult !== null) {
            return $validationResult;
        }

        if (! $this->validator->shouldRunAgain($record)) {
            return $this->createSkippedResult();
        }

        return $this->executeTask($record, $startTime);
    }

    /**
     * Validates the task before execution.
     *
     * @param  RecurringTaskRecord  $record  The task record
     * @return ExecutionResultRecord|null The validation error result or null if valid
     */
    private function validateTask(RecurringTaskRecord $record): ?ExecutionResultRecord
    {
        if ($this->validator->canRun($record)) {
            return null;
        }

        $errors = $this->validator->getValidationErrors($record);
        $errorMessage = $errors->count() > 0 ? $errors->join(', ') : 'Task cannot run';

        return $this->createValidationErrorResult($record, $errorMessage);
    }

    /**
     * Executes the task.
     *
     * @param  RecurringTaskRecord  $record  The task record
     * @param  Iso8601DateTimeVO  $startTime  The start timestamp
     * @return ExecutionResultRecord The execution result
     */
    private function executeTask(RecurringTaskRecord $record, Iso8601DateTimeVO $startTime): ExecutionResultRecord
    {
        $this->logger->logStart($record);

        $task = $this->instantiateTask($record);
        $error = null;
        $success = false;

        try {
            $task->execute($record->payload);
            $success = true;

            $duration = $startTime->elapsed();
            $this->logger->logSuccess($record, new MillisecondsVO((int) $duration->toMilliseconds()));
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $this->logger->logFailure($record, new DescriptionVO($error));
        }

        $this->repository->updateAfterRun($record, $success, $error !== null ? new DescriptionVO($error) : null);

        return $this->createExecutionResult($record, $success, $error, $startTime);
    }

    /**
     * Instantiates the task class.
     *
     * @param  RecurringTaskRecord  $record  The task record
     * @return AbstractRecurringTask The instantiated task
     */
    private function instantiateTask(RecurringTaskRecord $record): AbstractRecurringTask
    {
        $context = new RecurringTaskContext;
        $context->setAlias($record->alias);
        $context->setIntervalSeconds($record->interval_seconds);
        $context->setStartAt($record->start_at);
        $context->setEndAt($record->end_at);
        $context->setLastRunAt($record->last_run_at);
        $context->setLaravelApp($this->app);
        $context->setPayload($record->payload);

        $className = $record->fqcn->getValue();

        return new $className($context, $this->app->make(LoggerInterface::class), $this->hydration);
    }

    /**
     * Creates a validation error result.
     *
     * @param  RecurringTaskRecord  $record  The task record
     * @param  string  $errorMessage  The error message
     * @return ExecutionResultRecord The validation error result
     */
    private function createValidationErrorResult(RecurringTaskRecord $record, string $errorMessage): ExecutionResultRecord
    {
        return ExecutionResultRecord::from([
            'success' => false,
            'error' => TaskErrorRecord::from([
                'alias' => $record->alias,
                'fqcn' => $record->fqcn->getValue(),
                'description' => 'Validation failed: '.$errorMessage,
            ]),
            'execution_time' => new DurationVO(0.0),
        ]);
    }

    /**
     * Creates a skipped result.
     *
     * @return ExecutionResultRecord The skipped result
     */
    private function createSkippedResult(): ExecutionResultRecord
    {
        return ExecutionResultRecord::from([
            'success' => true,
            'error' => null,
            'execution_time' => new DurationVO(0.0),
        ]);
    }

    /**
     * Creates an execution result.
     *
     * @param  RecurringTaskRecord  $record  The task record
     * @param  bool  $success  Whether the execution succeeded
     * @param  string|null  $error  The error message if any
     * @param  Iso8601DateTimeVO  $startTime  The start timestamp
     * @return ExecutionResultRecord The execution result
     */
    private function createExecutionResult(
        RecurringTaskRecord $record,
        bool $success,
        ?string $error,
        Iso8601DateTimeVO $startTime
    ): ExecutionResultRecord {
        return ExecutionResultRecord::from([
            'success' => $success,
            'error' => $error ? TaskErrorRecord::from([
                'alias' => $record->alias,
                'fqcn' => $record->fqcn->getValue(),
                'description' => $error,
            ]) : null,
            'execution_time' => $startTime->elapsed(),
        ]);
    }
}
