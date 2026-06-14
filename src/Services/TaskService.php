<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Collections\TaskRecordCollection;
use AndyDefer\Task\Contracts\Services\BatchResultServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskBatchServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskFinderServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskRegistryServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskRunnerServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskValidatorServiceInterface;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\RecurringTaskResultRecord;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Records\UniqueTaskResultRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

/**
 * Wrapper centralisé de toutes les fonctionnalités du package.
 *
 * Cette classe délègue chaque méthode au service spécialisé correspondant.
 * Elle implémente l'interface unifiée qui étend toutes les interfaces spécialisées.
 */
final class TaskService implements TaskServiceInterface
{
    public function __construct(
        private readonly TaskRegistryServiceInterface $registry,
        private readonly TaskRunnerServiceInterface $runner,
        private readonly TaskValidatorServiceInterface $validator,
        private readonly TaskBatchServiceInterface $batch,
        private readonly BatchResultServiceInterface $batchResult,
        private readonly TaskFinderServiceInterface $finder,
    ) {}

    // ==================== TaskRegistryServiceInterface ====================

    public function register(
        string $taskClass,
        TaskPayloadRecord $payload,
        ?TaskConfigRecord $override_config = null,
    ): string {
        return $this->registry->register($taskClass, $payload, $override_config);
    }

    public function unregisterTask(TaskIdVO $taskId): void
    {
        $this->registry->unregisterTask($taskId);
    }

    public function unregisterRecurring(TaskSignatureVO $signature): void
    {
        $this->registry->unregisterRecurring($signature);
    }

    public function unregister(string $identifier): void
    {
        $this->registry->unregister($identifier);
    }

    // ==================== TaskRunnerServiceInterface ====================

    public function runTask(TaskRecord $task): bool
    {
        return $this->runner->runTask($task);
    }

    public function runRecurringTask(RecurringTaskRecord $task): bool
    {
        return $this->runner->runRecurringTask($task);
    }

    // ==================== TaskValidatorServiceInterface ====================

    public function validateTaskClass(string $className): bool
    {
        return $this->validator->validateTaskClass($className);
    }

    public function canRunTask(TaskRecord $task): bool
    {
        return $this->validator->canRunTask($task);
    }

    public function isTaskExpired(TaskRecord $task): bool
    {
        return $this->validator->isTaskExpired($task);
    }

    public function shouldRunRecurringNow(RecurringTaskRecord $task): bool
    {
        return $this->validator->shouldRunRecurringNow($task);
    }

    public function shouldRunTaskNow(TaskRecord $task): bool
    {
        return $this->validator->shouldRunTaskNow($task);
    }

    public function getDelaySecondsForTask(TaskRecord $task): int
    {
        return $this->validator->getDelaySecondsForTask($task);
    }

    public function getGracePeriodDelay(TaskRecord $task): int
    {
        return $this->validator->getGracePeriodDelay($task);
    }

    public function isUniqueTaskWithGracePeriod(TaskRecord $task): bool
    {
        return $this->validator->isUniqueTaskWithGracePeriod($task);
    }

    // ==================== TaskBatchServiceInterface ====================

    public function process(?int $limit = null): BatchResultRecord
    {
        return $this->batch->process($limit);
    }

    public function processUniqueOnly(?int $limit = null): BatchResultRecord
    {
        return $this->batch->processUniqueOnly($limit);
    }

    public function processRecurringOnly(?int $limit = null): BatchResultRecord
    {
        return $this->batch->processRecurringOnly($limit);
    }

    // ==================== BatchResultServiceInterface ====================

    public function withUniqueTask(BatchResultRecord $record, UniqueTaskResultRecord $result): BatchResultRecord
    {
        return $this->batchResult->withUniqueTask($record, $result);
    }

    public function withRecurringTask(BatchResultRecord $record, RecurringTaskResultRecord $result): BatchResultRecord
    {
        return $this->batchResult->withRecurringTask($record, $result);
    }

    // ==================== TaskFinderServiceInterface ====================

    public function findTask(TaskIdVO $taskId): ?TaskRecord
    {
        return $this->finder->findTask($taskId);
    }

    public function findRecurringTask(TaskSignatureVO $signature): ?RecurringTaskRecord
    {
        return $this->finder->findRecurringTask($signature);
    }

    public function getPendingTasks(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection
    {
        return $this->finder->getPendingTasks($limit, $order);
    }

    public function getRecurringTasks(?int $limit = null, ?TaskOrder $order = TaskOrder::OLDEST): RecurringTaskRecordCollection
    {
        return $this->finder->getRecurringTasks($limit, $order);
    }

    public function taskExists(TaskIdVO $taskId): bool
    {
        return $this->finder->taskExists($taskId);
    }

    public function recurringTaskExists(TaskSignatureVO $signature): bool
    {
        return $this->finder->recurringTaskExists($signature);
    }

    public function countPendingTasks(): int
    {
        return $this->finder->countPendingTasks();
    }

    public function countRecurringTasks(): int
    {
        return $this->finder->countRecurringTasks();
    }
}
