<?php

declare(strict_types=1);

namespace AndyDefer\Task\Loggers;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Contracts\Loggers\RecurringTaskLoggerInterface;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;

/**
 * Logger for recurring task lifecycle events.
 *
 * Handles logging of start, success, failure, and state transitions
 * for recurring tasks using the application's logging system.
 */
final class RecurringTaskLogger implements RecurringTaskLoggerInterface
{
    private const LOG_TYPE = 'recurring_task';

    /**
     * Constructor for the recurring task logger.
     *
     * @param  LoggerInterface  $logger  The underlying logger instance
     * @param  HydrationService  $hydration  Service for object hydration
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HydrationService $hydration,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function logStart(RecurringTaskRecord $record): void
    {
        $payload = $this->createPayload([
            'event' => 'recurring_task_started',
            'alias' => $record->alias,
            'interval' => $record->interval_seconds,
            'last_run_at' => $record->last_run_at,
        ]);

        $this->logger->info($this->createLogRecord($payload));
    }

    /**
     * {@inheritDoc}
     */
    public function logSuccess(RecurringTaskRecord $record, MillisecondsVO $executionTime): void
    {
        $payload = $this->createPayload([
            'event' => 'recurring_task_completed',
            'alias' => $record->alias,
            'execution_time' => $executionTime,
        ]);

        $this->logger->info($this->createLogRecord($payload));
    }

    /**
     * {@inheritDoc}
     */
    public function logFailure(RecurringTaskRecord $record, DescriptionVO $error): void
    {
        $payload = $this->createPayload([
            'event' => 'recurring_task_failed',
            'alias' => $record->alias,
            'error' => $error->getValue(),
        ]);

        $this->logger->error($this->createLogRecord($payload));
    }

    /**
     * {@inheritDoc}
     */
    public function logMoveToRunning(RecurringTaskRecord $record): void
    {
        $payload = $this->createPayload([
            'event' => 'recurring_task_moved_to_running',
            'alias' => $record->alias,
        ]);

        $this->logger->info($this->createLogRecord($payload));
    }

    /**
     * {@inheritDoc}
     */
    public function logMoveToFinished(RecurringTaskRecord $record): void
    {
        $payload = $this->createPayload([
            'event' => 'recurring_task_moved_to_finished',
            'alias' => $record->alias,
            'end_at' => $record->end_at,
        ]);

        $this->logger->info($this->createLogRecord($payload));
    }

    /**
     * Creates a hydrated payload for logging.
     *
     * @param  array<string, mixed>  $data  The payload data
     * @return StrictDataObject The hydrated payload
     */
    private function createPayload(array $data): StrictDataObject
    {
        return $this->hydration->hydrate(StrictDataObject::class, $data);
    }

    /**
     * Creates a log record with the given payload.
     *
     * @param  StrictDataObject  $payload  The log payload
     * @return LogDataRecord The created log record
     */
    private function createLogRecord(StrictDataObject $payload): LogDataRecord
    {
        return new LogDataRecord(
            type: self::LOG_TYPE,
            payload: $payload
        );
    }
}
