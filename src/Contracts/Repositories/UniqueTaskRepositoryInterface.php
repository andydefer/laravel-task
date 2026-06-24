<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Repository\AbstractRepositoryInterface;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use Illuminate\Support\Collection;

interface UniqueTaskRepositoryInterface extends AbstractRepositoryInterface
{
    // ==================== FINDERS ====================

    public function findPending(LimitVO $limit = new LimitVO): Collection;

    public function findCompleted(LimitVO $limit = new LimitVO): Collection;

    public function findFailed(LimitVO $limit = new LimitVO): Collection;

    public function findCanceled(LimitVO $limit = new LimitVO): Collection;

    public function findReadyToRun(Iso8601DateTimeVO $now, ?LimitVO $limit = new LimitVO): Collection;

    public function findExpired(Iso8601DateTimeVO $now, ?LimitVO $limit = new LimitVO): Collection;

    public function findById(TaskIdVO $id): ?UniqueTask;

    /**
     * Trouve une tâche par son alias.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     */
    public function findByAlias(TaskAliasVO $alias): ?UniqueTask;

    // ==================== MOVES ====================

    public function updateAttempts(UniqueTaskRecord $task, CounterVO $newAttempts): bool;

    public function addDebug(UniqueTaskRecord $task, ExecutionStatus $status, DescriptionVO $info): bool;

    public function moveToCompleted(UniqueTaskRecord $task): bool;

    public function moveToFailed(UniqueTaskRecord $task): bool;

    public function moveToCanceled(UniqueTaskRecord $task): bool;

    // ==================== COUNTS ====================

    public function countPending(): CounterVO;

    public function countCompleted(): CounterVO;

    public function countFailed(): CounterVO;

    public function countCanceled(): CounterVO;
}
