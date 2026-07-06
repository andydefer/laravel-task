<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Repository\AbstractRepositoryInterface;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\RecurringTaskReadyToRunResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use Illuminate\Support\Collection;

/**
 * Interface for the recurring task repository.
 *
 * Defines the contract for managing recurring tasks including
 * finding tasks by status, state transitions, and counting.
 */
interface RecurringTaskRepositoryInterface extends AbstractRepositoryInterface
{
    // ==================== FINDERS ====================

    /**
     * Find all tasks in WAITING state.
     *
     * @param  LimitVO  $limit  Optional limit for the number of results
     * @return Collection<int, RecurringTask> Collection of recurring tasks
     */
    public function findWaiting(LimitVO $limit = new LimitVO): Collection;

    /**
     * Find all tasks in PLAYING state.
     *
     * @param  LimitVO  $limit  Optional limit for the number of results
     * @return Collection<int, RecurringTask> Collection of recurring tasks
     */
    public function findPlaying(LimitVO $limit = new LimitVO): Collection;

    /**
     * Find all tasks in PAUSED state.
     *
     * @param  LimitVO  $limit  Optional limit for the number of results
     * @return Collection<int, RecurringTask> Collection of recurring tasks
     */
    public function findPaused(LimitVO $limit = new LimitVO): Collection;

    /**
     * Find all tasks in FINISHED state.
     *
     * @param  LimitVO  $limit  Optional limit for the number of results
     * @return Collection<int, RecurringTask> Collection of recurring tasks
     */
    public function findFinished(LimitVO $limit = new LimitVO): Collection;

    /**
     * Find all tasks in CANCELED state.
     *
     * @param  LimitVO  $limit  Optional limit for the number of results
     * @return Collection<int, RecurringTask> Collection of recurring tasks
     */
    public function findCanceled(LimitVO $limit = new LimitVO): Collection;

    /**
     * Find tasks that are ready to run.
     *
     * Includes automatic state transitions (WAITING → PLAYING, etc.)
     * and returns only tasks in PLAYING state.
     *
     * @param  Iso8601DateTimeVO|null  $now  Current timestamp (uses now if null)
     * @param  LimitVO|null  $limit  Optional limit for the number of results
     * @return RecurringTaskReadyToRunResultRecord Result containing tasks and fresh state
     */
    public function findReadyToRun(?Iso8601DateTimeVO $now = null, ?LimitVO $limit = null): RecurringTaskReadyToRunResultRecord;

    /**
     * Find a task by its alias.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @return RecurringTask|null The found task or null if not found
     */
    public function findByAlias(TaskAliasVO $alias): ?RecurringTask;

    // ==================== MOVES ====================

    /**
     * Move a task to PLAYING state.
     *
     * @param  RecurringTaskRecord  $task  The task record to update
     * @return bool True if the update was successful
     */
    public function moveToPlaying(RecurringTaskRecord $task): bool;

    /**
     * Move a task to PAUSED state.
     *
     * @param  RecurringTaskRecord  $task  The task record to update
     * @return bool True if the update was successful
     */
    public function moveToPaused(RecurringTaskRecord $task): bool;

    /**
     * Move a task to WAITING state.
     *
     * @param  RecurringTaskRecord  $task  The task record to update
     * @return bool True if the update was successful
     */
    public function moveToWaiting(RecurringTaskRecord $task): bool;

    /**
     * Move a task to FINISHED state.
     *
     * @param  RecurringTaskRecord  $task  The task record to update
     * @return bool True if the update was successful
     */
    public function moveToFinished(RecurringTaskRecord $task): bool;

    /**
     * Move a task to CANCELED state.
     *
     * @param  RecurringTaskRecord  $task  The task record to update
     * @return bool True if the update was successful
     */
    public function moveToCanceled(RecurringTaskRecord $task): bool;

    // ==================== UPDATE ====================

    /**
     * Update a task after execution.
     *
     * Updates last_run_at, failed_attempts, and adds debug information.
     *
     * @param  RecurringTaskRecord  $task  The task record to update
     * @param  bool  $success  Whether the execution was successful
     * @param  DescriptionVO|null  $error  Optional error message on failure
     * @return bool True if the update was successful
     */
    public function updateAfterRun(RecurringTaskRecord $task, bool $success, ?DescriptionVO $error = null): bool;

    // ==================== COUNTS ====================

    /**
     * Count tasks in WAITING state.
     *
     * @return CounterVO The number of tasks
     */
    public function countWaiting(): CounterVO;

    /**
     * Count tasks in PLAYING state.
     *
     * @return CounterVO The number of tasks
     */
    public function countPlaying(): CounterVO;

    /**
     * Count tasks in PAUSED state.
     *
     * @return CounterVO The number of tasks
     */
    public function countPaused(): CounterVO;

    /**
     * Count tasks in FINISHED state.
     *
     * @return CounterVO The number of tasks
     */
    public function countFinished(): CounterVO;

    /**
     * Count tasks in CANCELED state.
     *
     * @return CounterVO The number of tasks
     */
    public function countCanceled(): CounterVO;
}
