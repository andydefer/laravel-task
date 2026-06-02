<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts;

use AndyDefer\Task\Records\BatchResultRecord;

/**
 * Orchestrates the batch processing of tasks with filtering capabilities.
 *
 * This interface defines a contract for processors that can handle collections
 * of tasks, applying different filtering strategies based on task recurrence
 * patterns. Each processing method returns a comprehensive batch result
 * containing statistics and outcomes for all processed tasks.
 *
 * Implementations are responsible for:
 * - Task queue management
 * - Batch execution orchestration
 * - Error handling and recovery
 * - Result aggregation
 */
interface TaskProcessorInterface
{
    /**
     * Processes all available tasks in the current batch.
     *
     * Executes every pending task regardless of its recurrence type.
     * This is the standard processing method for normal batch operations.
     *
     * @param  int|null  $limit  Maximum number of tasks to process
     * @return BatchResultRecord An immutable result record containing:
     *                           - Total tasks processed
     *                           - Success/failure counts
     *                           - Individual task outcomes
     *                           - Execution metadata
     */
    public function process(?int $limit = null): BatchResultRecord;

    /**
     * Processes only unique (non-recurring) tasks in the batch.
     *
     * Filters out all recurring tasks and processes only one-time tasks.
     * Useful for scenarios where recurring tasks should be handled separately
     * or deferred to another processing cycle.
     *
     * Tasks are considered "unique" when they are scheduled to run exactly once.
     *
     * @param  int|null  $limit  Maximum number of tasks to process
     * @return BatchResultRecord An immutable result record for unique tasks only,
     *                           following the same structure as the standard process method
     */
    public function processUniqueOnly(?int $limit = null): BatchResultRecord;

    /**
     * Processes only recurring tasks in the batch.
     *
     * Filters out all unique (one-time) tasks and processes only tasks that
     * are configured to run on a recurring schedule (daily, weekly, hourly, etc.).
     *
     * This method is typically used when:
     * - Unique tasks need prioritization elsewhere
     * - Recurring tasks require special resource allocation
     * - Different SLAs apply to recurring vs one-time tasks
     *
     * @param  int|null  $limit  Maximum number of tasks to process
     * @return BatchResultRecord An immutable result record for recurring tasks only,
     *                           following the same structure as the standard process method
     */
    public function processRecurringOnly(?int $limit = null): BatchResultRecord;
}
