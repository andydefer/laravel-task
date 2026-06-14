<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;

interface TaskValidatorServiceInterface
{
    /**
     * Validates that a class exists and extends AbstractTask.
     */
    public function validateTaskClass(string $className): bool;

    /**
     * Checks if a unique task can be executed.
     */
    public function canRunTask(TaskRecord $task): bool;

    /**
     * Checks if a unique task has expired.
     */
    public function isTaskExpired(TaskRecord $task): bool;

    /**
     * Checks if a recurring task is ready to run.
     */
    public function shouldRunRecurringNow(RecurringTaskRecord $task): bool;

    /**
     * Checks if a unique task should run now (without grace period logic).
     */
    public function shouldRunTaskNow(TaskRecord $task): bool;

    /**
     * Gets the delay seconds for a task.
     */
    public function getDelaySecondsForTask(TaskRecord $task): int;

    /**
     * Calculates the grace period delay for an expired task.
     */
    public function getGracePeriodDelay(TaskRecord $task): int;

    /**
     * Checks if a unique task qualifies for grace period.
     */
    public function isUniqueTaskWithGracePeriod(TaskRecord $task): bool;
}
