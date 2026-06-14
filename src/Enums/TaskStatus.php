<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

/**
 * Represents the lifecycle state of a task.
 *
 * Tracks a task's progress from creation to completion, including
 * pending, running, success, and failure states. This enum is used
 * by the task scheduler and executor to manage task flow.
 */
enum TaskStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';

    /**
     * Returns a human-readable label for the status.
     *
     * @return string Localized or user-friendly label
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::RUNNING => 'Running',
            self::SUCCESS => 'Success',
            self::FAILED => 'Failed',
        };
    }

    /**
     * Checks if the task is waiting to be executed.
     *
     * A pending task has been created but not yet picked up by an executor.
     * This is the initial state for all tasks.
     *
     * @return bool True if status is PENDING, false otherwise
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Checks if the task is currently being executed.
     *
     * A running task has been picked up by an executor and is in progress.
     * The task should transition to SUCCESS or FAILED after completion.
     *
     * @return bool True if status is RUNNING, false otherwise
     */
    public function isRunning(): bool
    {
        return $this === self::RUNNING;
    }

    /**
     * Checks if the task completed successfully.
     *
     * A successful task has finished execution without errors.
     * This is a terminal state.
     *
     * @return bool True if status is SUCCESS, false otherwise
     */
    public function isSuccess(): bool
    {
        return $this === self::SUCCESS;
    }

    /**
     * Checks if the task failed after maximum attempts.
     *
     * A failed task exhausted all retry attempts without succeeding.
     * This is a terminal state.
     *
     * @return bool True if status is FAILED, false otherwise
     */
    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    /**
     * Checks if the status represents a terminal state.
     *
     * Terminal states (SUCCESS, FAILED) indicate that no further
     * processing will occur for this task.
     *
     * @return bool True if status is SUCCESS or FAILED, false otherwise
     */
    public function isTerminal(): bool
    {
        return $this === self::SUCCESS || $this === self::FAILED;
    }

    /**
     * Checks if the status represents an active state.
     *
     * Active states (PENDING, RUNNING) indicate that the task is
     * still in the execution pipeline.
     *
     * @return bool True if status is PENDING or RUNNING, false otherwise
     */
    public function isActive(): bool
    {
        return $this === self::PENDING || $this === self::RUNNING;
    }
}
