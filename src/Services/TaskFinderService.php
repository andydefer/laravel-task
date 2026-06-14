<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Collections\TaskRecordCollection;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\TaskFinderServiceInterface;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

/**
 * Implémentation du service de recherche de tâches.
 */
final class TaskFinderService implements TaskFinderServiceInterface
{
    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly RecurringTaskRepositoryInterface $recurringTaskRepository,
    ) {}

    public function findTask(TaskIdVO $taskId): ?TaskRecord
    {
        return $this->taskRepository->find($taskId);
    }

    public function findRecurringTask(TaskSignatureVO $signature): ?RecurringTaskRecord
    {
        return $this->recurringTaskRepository->find($signature);
    }

    public function getPendingTasks(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection
    {
        return $this->taskRepository->findAll($limit, $order);
    }

    public function getRecurringTasks(?int $limit = null, ?TaskOrder $order = TaskOrder::OLDEST): RecurringTaskRecordCollection
    {
        return $this->recurringTaskRepository->findAll($limit, $order);
    }

    public function taskExists(TaskIdVO $taskId): bool
    {
        return $this->taskRepository->find($taskId) !== null;
    }

    public function recurringTaskExists(TaskSignatureVO $signature): bool
    {
        return $this->recurringTaskRepository->find($signature) !== null;
    }

    public function countPendingTasks(): int
    {
        return $this->taskRepository->findAll()->count();
    }

    public function countRecurringTasks(): int
    {
        return $this->recurringTaskRepository->findAll()->count();
    }
}
