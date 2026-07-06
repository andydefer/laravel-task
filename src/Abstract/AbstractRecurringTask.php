<?php

declare(strict_types=1);

namespace AndyDefer\Task\Abstract;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Contracts\Abstract\TaskInterface;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use Throwable;

/**
 * Abstract base class for recurring tasks executed on a schedule.
 *
 * Provides a standardized execution workflow with logging, error handling,
 * and lifecycle hooks for scheduled operations. This class ensures consistent
 * behavior across all recurring tasks while allowing concrete implementations
 * to define their specific processing logic.
 */
abstract class AbstractRecurringTask implements TaskInterface
{
    /**
     * @var RecurringTaskContext The task execution context containing schedule and configuration
     */
    protected RecurringTaskContext $context;

    /**
     * @var LoggerInterface Logger instance for recording task lifecycle events
     */
    protected LoggerInterface $logger;

    /**
     * @var HydrationService Service responsible for hydrating data objects
     */
    protected HydrationService $hydration;

    /**
     * Constructor for the recurring task.
     *
     * @param  RecurringTaskContext  $context  The task context with execution parameters
     * @param  LoggerInterface  $logger  Logger for task lifecycle events
     * @param  HydrationService  $hydration  Service for object hydration
     */
    final public function __construct(
        RecurringTaskContext $context,
        LoggerInterface $logger,
        HydrationService $hydration,
    ) {
        $this->context = $context;
        $this->logger = $logger;
        $this->hydration = $hydration;
    }

    /**
     * Executes the main processing logic for this task.
     *
     * This method contains the business logic that will be executed
     * when the recurring task is triggered.
     *
     * @throws Throwable When processing fails
     */
    abstract protected function process(): void;

    /**
     * Hook executed before the main processing starts.
     *
     * Can be overridden to perform setup, validation, or pre-processing
     * operations before the task executes.
     *
     * @param  StrictDataObject  $payload  The input data for the task
     */
    protected function before(StrictDataObject $payload): void {}

    /**
     * Hook executed after the main processing completes.
     *
     * Can be overridden to perform cleanup, notifications, or post-processing
     * operations based on the execution result.
     *
     * @param  bool  $success  Indicates whether the task completed successfully
     * @param  DescriptionVO|null  $error  Error description when task failed
     */
    protected function after(bool $success, ?DescriptionVO $error = null): void {}

    /**
     * Executes the task with the given payload.
     *
     * Orchestrates the complete task execution workflow including logging,
     * lifecycle hooks, error handling, and state management.
     *
     * @param  StrictDataObject  $payload  Input data for the task execution
     *
     * @throws Throwable When execution fails and exceptions are propagated
     */
    final public function execute(StrictDataObject $payload): void
    {
        $this->context->setPayload($payload);

        $this->logTaskStarted();

        $this->before($payload);

        try {
            $this->process();
            $this->after(true);
            $this->logTaskCompleted();
        } catch (Throwable $e) {
            $this->after(false, DescriptionVO::from($e->getMessage()));
            $this->logTaskFailed($e);
            throw $e;
        }
    }

    /**
     * Logs an informational message from the task.
     *
     * @param  DescriptionVO  $message  The informational message to log
     */
    public function info(DescriptionVO $message): void
    {
        $this->logger->info($this->createLogRecord('info', $message));
    }

    /**
     * Logs an error message from the task.
     *
     * @param  DescriptionVO  $message  The error message to log
     */
    public function error(DescriptionVO $message): void
    {
        $this->logger->error($this->createLogRecord('error', $message));
    }

    /**
     * Creates a log record for the task output.
     *
     * @param  string  $event  The type of event being logged
     * @param  DescriptionVO  $message  The message content
     * @return LogDataRecord The formatted log record
     */
    private function createLogRecord(string $event, DescriptionVO $message): LogDataRecord
    {
        return new LogDataRecord(
            type: 'recurring_task_output',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => $event,
                'message' => $message->getValue(),
            ])
        );
    }

    /**
     * Logs the start of a task execution.
     */
    private function logTaskStarted(): void
    {
        $this->logger->info($this->createLifecycleLogRecord('task_started', [
            'alias' => $this->context->getAlias(),
            'interval_seconds' => $this->context->getIntervalSeconds(),
            'next_run_at' => $this->context->getNextRunAt(),
        ]));
    }

    /**
     * Logs the successful completion of a task.
     */
    private function logTaskCompleted(): void
    {
        $this->logger->info($this->createLifecycleLogRecord('task_completed', [
            'alias' => $this->context->getAlias()->getValue(),
            'status' => 'success',
        ]));
    }

    /**
     * Logs a failed task execution.
     *
     * @param  Throwable  $error  The exception that caused the failure
     */
    private function logTaskFailed(Throwable $error): void
    {
        $this->logger->error($this->createLifecycleLogRecord('task_failed', [
            'alias' => $this->context->getAlias()->getValue(),
            'status' => 'failed',
            'error' => $error->getMessage(),
        ]));
    }

    /**
     * Creates a lifecycle log record for the task.
     *
     * @param  string  $event  The lifecycle event name
     * @param  array<string, mixed>  $payload  The event data
     * @return LogDataRecord The formatted log record
     */
    private function createLifecycleLogRecord(string $event, array $payload): LogDataRecord
    {
        return new LogDataRecord(
            type: 'recurring_task',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => $event,
                ...$payload,
            ])
        );
    }
}
