<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Collections\UniqueTaskRecordCollection;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\TaskRunResultRecord;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;

/**
 * Interface for unique task service.
 *
 * Provides methods for registering, executing, and managing unique tasks.
 * Unique tasks are one-time tasks that can be scheduled for future execution.
 */
interface UniqueTaskServiceInterface
{
    /**
     * Registers a new unique task.
     *
     * @param  UniqueTaskFqcnVO  $fqcn  Task class (must extend AbstractUniqueTask)
     * @param  StrictDataObject  $payload  Task payload data
     * @param  UniqueTaskConfigRecord  $config  Task configuration
     * @return TaskAliasVO Alias of the created task
     */
    public function register(
        UniqueTaskFqcnVO $fqcn,
        StrictDataObject $payload,
        UniqueTaskConfigRecord $config
    ): TaskAliasVO;

    /**
     * Runs a specific unique task.
     *
     * @param  TaskAliasVO  $alias  Alias of the task to run
     * @return TaskRunResultRecord Execution result
     */
    public function run(TaskAliasVO $alias): TaskRunResultRecord;

    /**
     * Processes all unique tasks that are ready to run (scheduled_at <= now).
     *
     * @param  LimitVO  $limit  Maximum number of tasks to process
     * @param  callable|null  $onProgress  Optional callback for progress tracking
     * @return ProcessResultRecord Execution results
     */
    public function process(LimitVO $limit = new LimitVO, ?callable $onProgress = null): ProcessResultRecord;

    /**
     * Cancels a pending task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @param  DescriptionVO|null  $reason  Cancellation reason
     * @return bool True if the task was cancelled, false otherwise
     */
    public function cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool;

    /**
     * Reschedules a pending task to a new execution date.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @param  Iso8601DateTimeVO  $newScheduledAt  New scheduled date
     * @return bool True if the task was rescheduled, false otherwise
     */
    public function reschedule(TaskAliasVO $alias, Iso8601DateTimeVO $newScheduledAt): bool;

    /**
     * Extends the grace period of a pending task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @param  DurationVO  $extraSeconds  Additional seconds to add
     * @return bool True if the grace period was extended, false otherwise
     */
    public function extendGracePeriod(TaskAliasVO $alias, DurationVO $extraSeconds): bool;

    /**
     * Finds a task by its alias.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @return UniqueTaskRecord|null The task record or null if not found
     */
    public function find(TaskAliasVO $alias): ?UniqueTaskRecord;

    /**
     * Finds all pending tasks.
     *
     * @param  LimitVO  $limit  Maximum number of tasks
     * @return UniqueTaskRecordCollection Collection of pending tasks
     */
    public function findPending(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection;

    /**
     * Finds all successfully completed tasks.
     *
     * @param  LimitVO  $limit  Maximum number of tasks
     * @return UniqueTaskRecordCollection Collection of completed tasks
     */
    public function findCompleted(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection;

    /**
     * Finds all failed tasks.
     *
     * @param  LimitVO  $limit  Maximum number of tasks
     * @return UniqueTaskRecordCollection Collection of failed tasks
     */
    public function findFailed(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection;

    /**
     * Finds all cancelled tasks.
     *
     * @param  LimitVO  $limit  Maximum number of tasks
     * @return UniqueTaskRecordCollection Collection of cancelled tasks
     */
    public function findCanceled(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection;

    /**
     * Checks if a task exists.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @return bool True if the task exists
     */
    public function exists(TaskAliasVO $alias): bool;

    /**
     * Permanently deletes a task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @return bool True if the task was deleted, false otherwise
     */
    public function delete(TaskAliasVO $alias): bool;

    /**
     * Counts the total number of unique tasks.
     *
     * @return CounterVO Total count
     */
    public function count(): CounterVO;

    /**
     * Counts the number of pending tasks.
     *
     * @return CounterVO Pending count
     */
    public function countPending(): CounterVO;

    /**
     * Counts the number of completed tasks.
     *
     * @return CounterVO Completed count
     */
    public function countCompleted(): CounterVO;

    /**
     * Counts the number of failed tasks.
     *
     * @return CounterVO Failed count
     */
    public function countFailed(): CounterVO;

    /**
     * Counts the number of cancelled tasks.
     *
     * @return CounterVO Cancelled count
     */
    public function countCanceled(): CounterVO;
}
