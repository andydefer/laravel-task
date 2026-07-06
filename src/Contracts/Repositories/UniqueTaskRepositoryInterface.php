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
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Support\Collection;

/**
 * Interface for the unique task repository.
 *
 * Defines the contract for managing unique tasks including
 * finding tasks by status, state transitions, and counting.
 */
interface UniqueTaskRepositoryInterface extends AbstractRepositoryInterface
{
    // ==================== FINDERS ====================

    /**
     * Find all tasks in PENDING state.
     *
     * @param  LimitVO  $limit  Optional limit for the number of results
     * @return Collection<int, UniqueTask> Collection of unique tasks
     */
    public function findPending(LimitVO $limit = new LimitVO): Collection;

    /**
     * Find all tasks in COMPLETED state.
     *
     * @param  LimitVO  $limit  Optional limit for the number of results
     * @return Collection<int, UniqueTask> Collection of unique tasks
     */
    public function findCompleted(LimitVO $limit = new LimitVO): Collection;

    /**
     * Find all tasks in FAILED state.
     *
     * @param  LimitVO  $limit  Optional limit for the number of results
     * @return Collection<int, UniqueTask> Collection of unique tasks
     */
    public function findFailed(LimitVO $limit = new LimitVO): Collection;

    /**
     * Find all tasks in CANCELED state.
     *
     * @param  LimitVO  $limit  Optional limit for the number of results
     * @return Collection<int, UniqueTask> Collection of unique tasks
     */
    public function findCanceled(LimitVO $limit = new LimitVO): Collection;

    /**
     * Find tasks that are ready to run.
     *
     * Uses lockForUpdate() to prevent concurrency issues.
     *
     * @param  Iso8601DateTimeVO  $now  Current timestamp
     * @param  LimitVO|null  $limit  Optional limit for the number of results
     * @return Collection<int, UniqueTask> Collection of ready tasks
     */
    public function findReadyToRun(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection;

    /**
     * Find tasks that have expired.
     *
     * @param  Iso8601DateTimeVO  $now  Current timestamp
     * @param  LimitVO|null  $limit  Optional limit for the number of results
     * @return Collection<int, UniqueTask> Collection of expired tasks
     */
    public function findExpired(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection;

    /**
     * Find a task by its ID.
     *
     * @param  UuidVO  $id  The task ID
     * @return UniqueTask|null The found task or null if not found
     */
    public function findById(UuidVO $id): ?UniqueTask;

    /**
     * Find a task by its alias.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @return UniqueTask|null The found task or null if not found
     */
    public function findByAlias(TaskAliasVO $alias): ?UniqueTask;

    // ==================== MOVES ====================

    /**
     * Update the attempts counter for a task.
     *
     * @param  UniqueTaskRecord  $task  The task record to update
     * @param  CounterVO  $newAttempts  The new attempts value
     * @return bool True if the update was successful
     */
    public function updateAttempts(UniqueTaskRecord $task, CounterVO $newAttempts): bool;

    /**
     * Add debug information for a task.
     *
     * @param  UniqueTaskRecord  $task  The task record
     * @param  ExecutionStatus  $status  The execution status
     * @param  DescriptionVO  $info  The debug information
     * @return bool True if the debug was added successfully
     */
    public function addDebug(UniqueTaskRecord $task, ExecutionStatus $status, DescriptionVO $info): bool;

    /**
     * Move a task to COMPLETED state.
     *
     * @param  UniqueTaskRecord  $task  The task record to update
     * @return bool True if the update was successful
     */
    public function moveToCompleted(UniqueTaskRecord $task): bool;

    /**
     * Move a task to FAILED state.
     *
     * @param  UniqueTaskRecord  $task  The task record to update
     * @return bool True if the update was successful
     */
    public function moveToFailed(UniqueTaskRecord $task): bool;

    /**
     * Move a task to CANCELED state.
     *
     * @param  UniqueTaskRecord  $task  The task record to update
     * @return bool True if the update was successful
     */
    public function moveToCanceled(UniqueTaskRecord $task): bool;

    // ==================== COUNTS ====================

    /**
     * Count tasks in PENDING state.
     *
     * @return CounterVO The number of tasks
     */
    public function countPending(): CounterVO;

    /**
     * Count tasks in COMPLETED state.
     *
     * @return CounterVO The number of tasks
     */
    public function countCompleted(): CounterVO;

    /**
     * Count tasks in FAILED state.
     *
     * @return CounterVO The number of tasks
     */
    public function countFailed(): CounterVO;

    /**
     * Count tasks in CANCELED state.
     *
     * @return CounterVO The number of tasks
     */
    public function countCanceled(): CounterVO;
}
