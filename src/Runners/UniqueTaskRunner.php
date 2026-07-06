<?php

declare(strict_types=1);

namespace AndyDefer\Task\Runners;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Contexts\UniqueTaskContext;
use AndyDefer\Task\Contracts\Loggers\UniqueTaskLoggerInterface;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Runners\UniqueTaskRunnerInterface;
use AndyDefer\Task\Contracts\Validators\UniqueTaskValidatorInterface;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Records\ExecutionResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Runner for unique tasks.
 *
 * Handles the execution of unique tasks including validation,
 * logging, state management, and debugging.
 */
final class UniqueTaskRunner implements UniqueTaskRunnerInterface
{
    /**
     * Constructor for the unique task runner.
     *
     * @param  UniqueTaskValidatorInterface  $validator  The task validator
     * @param  UniqueTaskLoggerInterface  $logger  The task logger
     * @param  HydrationService  $hydration  The hydration service
     * @param  Application  $app  The application container
     * @param  UniqueTaskRepositoryInterface  $repository  The task repository
     */
    public function __construct(
        private readonly UniqueTaskValidatorInterface $validator,
        private readonly UniqueTaskLoggerInterface $logger,
        private readonly HydrationService $hydration,
        private readonly Application $app,
        private readonly UniqueTaskRepositoryInterface $repository,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function run(UniqueTaskRecord $record): ExecutionResultRecord
    {
        $startTime = new Iso8601DateTimeVO;

        $validationResult = $this->validateTask($record);
        if ($validationResult !== null) {
            return $validationResult;
        }

        return $this->executeTask($record, $startTime);
    }

    /**
     * Validates the task before execution.
     *
     * @param  UniqueTaskRecord  $record  The task record
     * @return ExecutionResultRecord|null The validation error result or null if valid
     */
    private function validateTask(UniqueTaskRecord $record): ?ExecutionResultRecord
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
     * @param  UniqueTaskRecord  $record  The task record
     * @param  Iso8601DateTimeVO  $startTime  The start timestamp
     * @return ExecutionResultRecord The execution result
     */
    private function executeTask(UniqueTaskRecord $record, Iso8601DateTimeVO $startTime): ExecutionResultRecord
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

        $this->updateTaskState($record, $success, $error);
        $this->addDebugInfo($record, $success, $error);

        return $this->createExecutionResult($record, $success, $error, $startTime);
    }

    /**
     * Instantiates the task class.
     *
     * @param  UniqueTaskRecord  $record  The task record
     * @return AbstractUniqueTask The instantiated task
     */
    private function instantiateTask(UniqueTaskRecord $record): AbstractUniqueTask
    {
        $context = new UniqueTaskContext;
        $context->setTaskId($record->id);
        $context->setAlias($record->alias);
        $context->setScheduledAt($record->scheduled_at);
        $context->setLaravelApp($this->app);
        $context->setPayload($record->payload);

        $className = $record->fqcn->getValue();

        return new $className($context, $this->app->make(LoggerInterface::class), $this->hydration);
    }

    /**
     * Updates the task state based on execution result.
     *
     * @param  UniqueTaskRecord  $record  The task record
     * @param  bool  $success  Whether the execution succeeded
     * @param  string|null  $error  The error message if any
     */
    private function updateTaskState(UniqueTaskRecord $record, bool $success, ?string $error): void
    {
        if ($success) {
            $this->repository->moveToCompleted($record);
        } else {
            $this->repository->moveToFailed($record);
        }
    }

    /**
     * Adds debug information for the task execution.
     *
     * @param  UniqueTaskRecord  $record  The task record
     * @param  bool  $success  Whether the execution succeeded
     * @param  string|null  $error  The error message if any
     */
    private function addDebugInfo(UniqueTaskRecord $record, bool $success, ?string $error): void
    {
        $status = $success ? ExecutionStatus::SUCCEEDED : ExecutionStatus::FAILED;
        $message = $success
            ? new DescriptionVO('Task executed successfully')
            : new DescriptionVO($error ?? 'Unknown error');

        $this->repository->addDebug($record, $status, $message);
    }

    /**
     * Creates a validation error result.
     *
     * @param  UniqueTaskRecord  $record  The task record
     * @param  string  $errorMessage  The error message
     * @return ExecutionResultRecord The validation error result
     */
    private function createValidationErrorResult(UniqueTaskRecord $record, string $errorMessage): ExecutionResultRecord
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
     * Creates an execution result.
     *
     * @param  UniqueTaskRecord  $record  The task record
     * @param  bool  $success  Whether the execution succeeded
     * @param  string|null  $error  The error message if any
     * @param  Iso8601DateTimeVO  $startTime  The start timestamp
     * @return ExecutionResultRecord The execution result
     */
    private function createExecutionResult(
        UniqueTaskRecord $record,
        bool $success,
        ?string $error,
        Iso8601DateTimeVO $startTime
    ): ExecutionResultRecord {
        return ExecutionResultRecord::from([
            'success' => $success,
            'error' => $error ? TaskErrorRecord::from([
                'alias' => $record->alias,
                'fqcn' => $record->fqcn,
                'description' => $error,
            ]) : null,
            'execution_time' => $startTime->elapsed(),
        ]);
    }
}
