<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts;

use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;

/**
 * Defines the contract for all executable tasks in the system.
 *
 * A task represents a unit of work that can be executed with different
 * operational modes and receives a typed payload for processing.
 *
 * Implementations should be stateless where possible and rely on the
 * payload record for all required data.
 */
interface TaskInterface
{
    /**
     * Retrieves the immutable configuration for this task.
     *
     * The configuration defines how the task should behave and is set
     * once at creation time. It should not change during the task's lifecycle.
     *
     * @return TaskConfigRecord The immutable configuration record
     */
    public function getConfig(): TaskConfigRecord;

    /**
     * Executes the task with the specified mode and payload.
     *
     * The execution behavior may vary based on the provided mode:
     * - Normal mode: Standard execution with side effects
     * - Dry-run mode: Validate without actual execution
     * - Simulation mode: Predict outcome without side effects
     *
     * @param  TaskMode  $mode  The execution mode determining side effects
     * @param  TaskPayloadRecord  $payload  The typed payload containing execution data
     *
     * @throws TaskExecutionException When execution fails in current mode
     * @throws InvalidPayloadException When payload validation fails
     */
    public function execute(TaskMode $mode, TaskPayloadRecord $payload): void;
}
