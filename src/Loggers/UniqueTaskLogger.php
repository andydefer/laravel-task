<?php

declare(strict_types=1);

namespace AndyDefer\Task\Loggers;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Contracts\Loggers\UniqueTaskLoggerInterface;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;

/**
 * Logger for unique task lifecycle events.
 *
 * Handles logging of start, success, failure, expiration, and max attempts
 * for unique tasks using the application's logging system.
 */
final class UniqueTaskLogger implements UniqueTaskLoggerInterface
{
    private const LOG_TYPE = 'unique_task';

    /**
     * Constructor for the unique task logger.
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
    public function logStart(UniqueTaskRecord $record): void
    {
        $payload = $this->createPayload([
            'event' => 'unique_task_started',
            'task_id' => $record->id,
            'alias' => $record->alias,
            'scheduled_at' => $record->scheduled_at,
            'attempts' => $record->attempts,
            'max_attempts' => $record->max_attempts,
        ]);

        $this->logger->info($this->createLogRecord($payload));
    }

    /**
     * {@inheritDoc}
     */
    public function logSuccess(UniqueTaskRecord $record, MillisecondsVO $executionTime): void
    {
        $payload = $this->createPayload([
            'event' => 'unique_task_completed',
            'task_id' => $record->id,
            'alias' => $record->alias,
            'execution_time' => $executionTime,
        ]);

        $this->logger->info($this->createLogRecord($payload));
    }

    /**
     * {@inheritDoc}
     */
    public function logFailure(UniqueTaskRecord $record, DescriptionVO $error): void
    {
        $payload = $this->createPayload([
            'event' => 'unique_task_failed',
            'task_id' => $record->id,
            'alias' => $record->alias,
            'attempts' => $record->attempts,
            'error' => $error->getValue(),
        ]);

        $this->logger->error($this->createLogRecord($payload));
    }

    /**
     * {@inheritDoc}
     */
    public function logExpired(UniqueTaskRecord $record): void
    {
        $payload = $this->createPayload([
            'event' => 'unique_task_expired',
            'task_id' => $record->id,
            'alias' => $record->alias,
            'scheduled_at' => $record->scheduled_at,
            'grace_period' => $record->grace_period_seconds,
        ]);

        $this->logger->warning($this->createLogRecord($payload));
    }

    /**
     * {@inheritDoc}
     */
    public function logMaxAttemptsReached(UniqueTaskRecord $record): void
    {
        $payload = $this->createPayload([
            'event' => 'unique_task_max_attempts',
            'task_id' => $record->id,
            'alias' => $record->alias,
            'attempts' => $record->attempts,
            'max_attempts' => $record->max_attempts,
        ]);

        $this->logger->warning($this->createLogRecord($payload));
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
