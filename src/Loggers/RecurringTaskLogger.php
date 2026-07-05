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

final class RecurringTaskLogger implements RecurringTaskLoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HydrationService $hydration,
    ) {}

    public function logStart(RecurringTaskRecord $record): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'recurring_task_started',
            'alias' => $record->alias,
            'interval' => $record->interval_seconds,
            'last_run_at' => $record->last_run_at,
        ]);

        $this->logger->info(new LogDataRecord(type: 'recurring_task', payload: $payload));
    }

    public function logSuccess(RecurringTaskRecord $record, MillisecondsVO $executionTime): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'recurring_task_completed',
            'alias' => $record->alias,
            'execution_time' => $executionTime,
        ]);

        $this->logger->info(new LogDataRecord(type: 'recurring_task', payload: $payload));
    }

    public function logFailure(RecurringTaskRecord $record, DescriptionVO $error): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'recurring_task_failed',
            'alias' => $record->alias,
            'error' => $error->getValue(),
        ]);

        $this->logger->error(new LogDataRecord(type: 'recurring_task', payload: $payload));
    }

    public function logMoveToRunning(RecurringTaskRecord $record): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'recurring_task_moved_to_running',
            'alias' => $record->alias,
        ]);

        $this->logger->info(new LogDataRecord(type: 'recurring_task', payload: $payload));
    }

    public function logMoveToFinished(RecurringTaskRecord $record): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'recurring_task_moved_to_finished',
            'alias' => $record->alias,
            'end_at' => $record->end_at,
        ]);

        $this->logger->info(new LogDataRecord(type: 'recurring_task', payload: $payload));
    }
}
