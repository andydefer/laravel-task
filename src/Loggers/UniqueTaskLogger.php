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

final class UniqueTaskLogger implements UniqueTaskLoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HydrationService $hydration,
    ) {}

    public function logStart(UniqueTaskRecord $record): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'unique_task_started',
            'task_id' => $record->id->value,
            'alias' => $record->alias->value,
            'scheduled_at' => $record->scheduled_at->value,
            'attempts' => $record->attempts->value,
            'max_attempts' => $record->max_attempts->value,
        ]);

        $this->logger->info(new LogDataRecord(type: 'unique_task', payload: $payload));
    }

    public function logSuccess(UniqueTaskRecord $record, MillisecondsVO $executionTime): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'unique_task_completed',
            'task_id' => $record->id->value,
            'alias' => $record->alias->value,
            'execution_time' => $executionTime,
        ]);

        $this->logger->info(new LogDataRecord(type: 'unique_task', payload: $payload));
    }

    public function logFailure(UniqueTaskRecord $record, DescriptionVO $error): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'unique_task_failed',
            'task_id' => $record->id->value,
            'alias' => $record->alias->value,
            'attempts' => $record->attempts->value,
            'error' => $error,
        ]);

        $this->logger->error(new LogDataRecord(type: 'unique_task', payload: $payload));
    }

    public function logExpired(UniqueTaskRecord $record): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'unique_task_expired',
            'task_id' => $record->id->value,
            'alias' => $record->alias->value,
            'scheduled_at' => $record->scheduled_at->value,
            'grace_period' => $record->grace_period_seconds,
        ]);

        $this->logger->warning(new LogDataRecord(type: 'unique_task', payload: $payload));
    }

    public function logMaxAttemptsReached(UniqueTaskRecord $record): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'unique_task_max_attempts',
            'task_id' => $record->id->value,
            'alias' => $record->alias->value,
            'attempts' => $record->attempts->value,
            'max_attempts' => $record->max_attempts->value,
        ]);

        $this->logger->warning(new LogDataRecord(type: 'unique_task', payload: $payload));
    }
}
