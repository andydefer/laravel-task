<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services\Watchs;

use AndyDefer\Task\Collections\TaskExecutionResultRecordCollection;
use AndyDefer\Task\Collections\TaskFqcnVOCollection;
use AndyDefer\Task\ValueObjects\LimitVO;

/**
 * Interface for executing tasks in parallel.
 *
 * Defines the contract for parallel task execution using pcntl_fork()
 * or fallback to sequential execution when forking is not available.
 */
interface ParallelExecutorInterface
{
    /**
     * Executes tasks in parallel with the specified configuration.
     *
     * @param  bool  $uniqueOnly  Whether to process only unique tasks
     * @param  bool  $recurringOnly  Whether to process only recurring tasks
     * @param  LimitVO|null  $limit  Maximum tasks per worker
     * @param  bool  $verbose  Whether to show detailed logs
     * @param  bool  $muted  Whether to suppress console output
     * @param  TaskFqcnVOCollection|null  $fqcns  Optional FQCN filter
     * @return TaskExecutionResultRecordCollection Results from all workers
     */
    public function execute(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $muted = false,
        ?TaskFqcnVOCollection $fqcns = null
    ): TaskExecutionResultRecordCollection;
}
