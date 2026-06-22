<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\Contracts\Repositories\TaskExecutionDebugRepositoryInterface;
use AndyDefer\Task\Contracts\Services\TaskExecutionDebugServiceInterface;
use Illuminate\Support\Collection;

final class TaskExecutionDebugService implements TaskExecutionDebugServiceInterface
{
    public function __construct(
        private readonly TaskExecutionDebugRepositoryInterface $repository,
    ) {}

    public function findByTask(string $taskType, string $taskIdentifier): Collection
    {
        return $this->repository->findByTask($taskType, $taskIdentifier);
    }

    public function addDebug(string $taskType, string $taskIdentifier, string $status, string $info): void
    {
        $this->repository->addDebug($taskType, $taskIdentifier, $status, $info);
    }

    public function findByRecurringTask(string $alias): Collection
    {
        return $this->findByTask('recurring', $alias);
    }

    public function findByUniqueTask(string $taskId): Collection
    {
        return $this->findByTask('unique', $taskId);
    }

    public function addDebugForRecurringTask(string $alias, string $status, string $info): void
    {
        $this->addDebug('recurring', $alias, $status, $info);
    }

    public function addDebugForUniqueTask(string $taskId, string $status, string $info): void
    {
        $this->addDebug('unique', $taskId, $status, $info);
    }

    public function clearTaskDebug(string $taskType, string $taskIdentifier): void
    {
        $this->repository->clearTaskDebug($taskType, $taskIdentifier);
    }

    public function countTaskDebug(string $taskType, string $taskIdentifier): int
    {
        return $this->repository->countTaskDebug($taskType, $taskIdentifier);
    }
}
