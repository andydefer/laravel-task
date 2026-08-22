<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Collections\TaskFqcnVOCollection;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRunResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;

/**
 * Interface for recurring task service.
 *
 * Provides methods for registering, executing, and managing recurring tasks.
 * Recurring tasks are tasks that run repeatedly at defined intervals.
 */
interface RecurringTaskServiceInterface
{
    /**
     * Registers a new recurring task.
     *
     * @param  RecurringTaskFqcnVO  $fqcn  Task class (must extend AbstractRecurringTask)
     * @param  StrictDataObject  $payload  Task payload data
     * @param  RecurringTaskConfigRecord  $config  Task configuration
     * @return TaskAliasVO Alias of the created task
     */
    public function register(
        RecurringTaskFqcnVO $fqcn,
        StrictDataObject $payload,
        RecurringTaskConfigRecord $config
    ): TaskAliasVO;

    /**
     * Runs a specific recurring task.
     *
     * @param  TaskAliasVO  $alias  Alias of the task to run
     * @return TaskRunResultRecord Execution result
     */
    public function run(TaskAliasVO $alias): TaskRunResultRecord;

    /**
     * Processes all recurring tasks that are ready to run.
     *
     * @param  LimitVO  $limit  Maximum number of tasks to process
     * @param  callable|null  $onProgress  Optional callback for progress tracking
     * @param  TaskFqcnVOCollection|null  $fqcns  Optional filter by FQCNs
     * @return ProcessResultRecord Execution results
     */
    public function process(
        LimitVO $limit = new LimitVO,
        ?callable $onProgress = null,
        ?TaskFqcnVOCollection $fqcns = null
    ): ProcessResultRecord;

    /**
     * Pauses a running recurring task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @return bool True if the task was paused, false otherwise
     */
    public function pause(TaskAliasVO $alias): bool;

    /**
     * Resumes a paused recurring task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @return bool True if the task was resumed, false otherwise
     */
    public function resume(TaskAliasVO $alias): bool;

    /**
     * Finishes a recurring task prematurely.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @return bool True if the task was finished, false otherwise
     */
    public function finish(TaskAliasVO $alias): bool;

    /**
     * Cancels a recurring task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @param  DescriptionVO|null  $reason  Cancellation reason
     * @return bool True if the task was cancelled, false otherwise
     */
    public function cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool;

    /**
     * Advances the start date of a recurring task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @param  Iso8601DateTimeVO  $newStartAt  New start date
     * @return bool True if the start date was advanced, false otherwise
     */
    public function advanceStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool;

    /**
     * Postpones the start date of a recurring task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @param  Iso8601DateTimeVO  $newStartAt  New start date
     * @return bool True if the start date was postponed, false otherwise
     */
    public function postponeStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool;

    /**
     * Changes the interval of a recurring task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @param  DurationVO  $intervalSeconds  New interval in seconds
     * @return bool True if the interval was changed, false otherwise
     */
    public function changeInterval(TaskAliasVO $alias, DurationVO $intervalSeconds): bool;

    /**
     * Extends the end date of a recurring task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @param  Iso8601DateTimeVO  $newEndAt  New end date
     * @return bool True if the end date was extended, false otherwise
     */
    public function extendEndAt(TaskAliasVO $alias, Iso8601DateTimeVO $newEndAt): bool;

    /**
     * Finds a recurring task by its alias.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @return RecurringTaskRecord|null The task record or null if not found
     */
    public function find(TaskAliasVO $alias): ?RecurringTaskRecord;

    /**
     * Finds all waiting tasks.
     *
     * @param  LimitVO  $limit  Maximum number of tasks
     * @return RecurringTaskRecordCollection Collection of waiting tasks
     */
    public function findWaiting(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection;

    /**
     * Finds all playing tasks.
     *
     * @param  LimitVO  $limit  Maximum number of tasks
     * @return RecurringTaskRecordCollection Collection of playing tasks
     */
    public function findPlaying(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection;

    /**
     * Finds all paused tasks.
     *
     * @param  LimitVO  $limit  Maximum number of tasks
     * @return RecurringTaskRecordCollection Collection of paused tasks
     */
    public function findPaused(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection;

    /**
     * Finds all finished tasks.
     *
     * @param  LimitVO  $limit  Maximum number of tasks
     * @return RecurringTaskRecordCollection Collection of finished tasks
     */
    public function findFinished(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection;

    /**
     * Finds all cancelled tasks.
     *
     * @param  LimitVO  $limit  Maximum number of tasks
     * @return RecurringTaskRecordCollection Collection of cancelled tasks
     */
    public function findCanceled(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection;

    /**
     * Checks if a recurring task exists.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @return bool True if the task exists
     */
    public function exists(TaskAliasVO $alias): bool;

    /**
     * Permanently deletes a recurring task.
     *
     * @param  TaskAliasVO  $alias  Task alias
     * @return bool True if the task was deleted, false otherwise
     */
    public function delete(TaskAliasVO $alias): bool;

    /**
     * Counts the total number of recurring tasks.
     *
     * @return CounterVO Total count
     */
    public function count(): CounterVO;

    /**
     * Counts the number of waiting tasks.
     *
     * @param  TaskFqcnVOCollection|null  $fqcns  Optional filter by FQCNs
     * @return CounterVO Waiting count
     */
    public function countWaiting(?TaskFqcnVOCollection $fqcns = null): CounterVO;

    /**
     * Counts the number of playing tasks.
     *
     * @param  TaskFqcnVOCollection|null  $fqcns  Optional filter by FQCNs
     * @return CounterVO Playing count
     */
    public function countPlaying(?TaskFqcnVOCollection $fqcns = null): CounterVO;

    /**
     * Counts the number of paused tasks.
     *
     * @param  TaskFqcnVOCollection|null  $fqcns  Optional filter by FQCNs
     * @return CounterVO Paused count
     */
    public function countPaused(?TaskFqcnVOCollection $fqcns = null): CounterVO;

    /**
     * Counts the number of finished tasks.
     *
     * @param  TaskFqcnVOCollection|null  $fqcns  Optional filter by FQCNs
     * @return CounterVO Finished count
     */
    public function countFinished(?TaskFqcnVOCollection $fqcns = null): CounterVO;

    /**
     * Counts the number of cancelled tasks.
     *
     * @param  TaskFqcnVOCollection|null  $fqcns  Optional filter by FQCNs
     * @return CounterVO Cancelled count
     */
    public function countCanceled(?TaskFqcnVOCollection $fqcns = null): CounterVO;
}
