<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Repository\AbstractRepositoryInterface;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Models\TaskExecutionDebug;
use AndyDefer\Task\Records\TaskExecutionDebugRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use Illuminate\Support\Collection;

/**
 * Interface for the task execution debug repository.
 *
 * Provides methods for storing, retrieving, and managing task execution debug information.
 */
interface TaskExecutionDebugRepositoryInterface extends AbstractRepositoryInterface
{
    /**
     * Find debug records by task alias.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @param  LimitVO|null  $limit  Optional limit for the number of records
     * @return Collection<int, TaskExecutionDebug> Collection of debug records
     */
    public function findByAlias(TaskAliasVO $alias, ?LimitVO $limit = null): Collection;

    /**
     * Find debug records by task FQCN.
     *
     * @param  TaskFqcnVO  $fqcn  The task fully qualified class name
     * @param  LimitVO|null  $limit  Optional limit for the number of records
     * @return Collection<int, TaskExecutionDebug> Collection of debug records
     */
    public function findByFqcn(TaskFqcnVO $fqcn, ?LimitVO $limit = null): Collection;

    /**
     * Find debug records by both alias and FQCN.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @param  TaskFqcnVO  $fqcn  The task fully qualified class name
     * @param  LimitVO|null  $limit  Optional limit for the number of records
     * @return Collection<int, TaskExecutionDebug> Collection of debug records
     */
    public function findByAliasAndFqcn(TaskAliasVO $alias, TaskFqcnVO $fqcn, ?LimitVO $limit = null): Collection;

    /**
     * Find debug records by execution status.
     *
     * @param  ExecutionStatus  $status  The execution status to filter by
     * @param  LimitVO|null  $limit  Optional limit for the number of records
     * @return Collection<int, TaskExecutionDebug> Collection of debug records
     */
    public function findByStatus(ExecutionStatus $status, ?LimitVO $limit = null): Collection;

    /**
     * Add a debug record for a task execution.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @param  TaskFqcnVO  $fqcn  The task fully qualified class name
     * @param  ExecutionStatus  $status  The execution status
     * @param  DescriptionVO  $info  Informational message
     * @param  MillisecondsVO|null  $duration_ms  Optional execution duration
     * @param  DescriptionVO|null  $error  Optional error message
     */
    public function addDebug(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?MillisecondsVO $duration_ms = null,
        ?DescriptionVO $error = null
    ): void;

    /**
     * Add a debug record with only the start time.
     *
     * Used when the execution has started but not yet completed.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @param  TaskFqcnVO  $fqcn  The task fully qualified class name
     * @param  ExecutionStatus  $status  The execution status
     * @param  DescriptionVO  $info  Informational message
     */
    public function addDebugWithStart(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info
    ): void;

    /**
     * Update a debug record with end time and result.
     *
     * Finds the most recent debug record for the given alias and FQCN
     * and updates it with the end time, status, and optional error/duration.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @param  TaskFqcnVO  $fqcn  The task fully qualified class name
     * @param  ExecutionStatus  $status  The final execution status
     * @param  DescriptionVO|null  $error  Optional error message
     * @param  MillisecondsVO|null  $duration_ms  Optional execution duration
     */
    public function updateDebugWithEnd(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        ?DescriptionVO $error = null,
        ?MillisecondsVO $duration_ms = null
    ): void;

    /**
     * Clear all debug records for a specific task alias.
     *
     * @param  TaskAliasVO  $alias  The task alias
     */
    public function clearByAlias(TaskAliasVO $alias): void;

    /**
     * Clear all debug records for a specific task class.
     *
     * @param  TaskFqcnVO  $fqcn  The task fully qualified class name
     */
    public function clearByFqcn(TaskFqcnVO $fqcn): void;

    /**
     * Count debug records for a specific task alias.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @return CounterVO The number of debug records
     */
    public function countByAlias(TaskAliasVO $alias): CounterVO;

    /**
     * Count debug records for a specific task class.
     *
     * @param  TaskFqcnVO  $fqcn  The task fully qualified class name
     * @return CounterVO The number of debug records
     */
    public function countByFqcn(TaskFqcnVO $fqcn): CounterVO;

    /**
     * Count debug records for a specific execution status.
     *
     * @param  ExecutionStatus  $status  The execution status
     * @return CounterVO The number of debug records
     */
    public function countByStatus(ExecutionStatus $status): CounterVO;

    /**
     * Convert an Eloquent model to a record object.
     *
     * @param  TaskExecutionDebug  $model  The Eloquent model
     * @return TaskExecutionDebugRecord The converted record
     */
    public function modelToRecord(TaskExecutionDebug $model): TaskExecutionDebugRecord;
}
