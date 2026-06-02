<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;
use Carbon\Carbon;

/**
 * Service for validating tasks and determining their executability.
 *
 * Handles task class validation, execution eligibility checks, grace period
 * management, and recurring task scheduling validation.
 */
class TaskValidatorService
{
    public function __construct(
        private readonly TaskConfig $config,
    ) {}

    /**
     * Validate that a class exists and extends AbstractTask.
     *
     * @param string $className Fully qualified class name to validate
     * @return bool True if the class is a valid task, false otherwise
     */
    public function validateTaskClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        $instance = new $className();
        return $instance instanceof AbstractTask;
    }

    /**
     * Check if a unique task can be executed.
     *
     * Validates status, attempts, time window, and grace period.
     *
     * @param TaskRecord $task The task to check
     * @return bool True if the task can be executed
     */
    public function canRunTask(TaskRecord $task): bool
    {
        if (!$task->status->isPending()) {
            return false;
        }

        if ($task->attempts >= $task->maxAttempts) {
            return false;
        }

        $now = $this->getCurrentTimestamp();
        $startAtTimestamp = strtotime($task->startAt);

        if ($now < $startAtTimestamp) {
            return false;
        }

        $endAtTimestamp = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;

        // Exact schedule enforcement - no grace period
        if ($task->enforceExactSchedule) {
            return $now <= $endAtTimestamp;
        }

        // Grace period for unique tasks (delaySeconds === 0)
        if ($task->delaySeconds === 0 && $this->config->gracePeriodEnabled()) {
            return $now <= $endAtTimestamp + $this->config->gracePeriodSeconds();
        }

        // Normal behavior for recurring tasks
        return $now <= $endAtTimestamp;
    }

    /**
     * Check if a unique task has expired.
     *
     * @param TaskRecord $task The task to check
     * @return bool True if the task is expired
     */
    public function isTaskExpired(TaskRecord $task): bool
    {
        $now = $this->getCurrentTimestamp();
        $endAtTimestamp = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;

        // Exact schedule enforcement - no grace period
        if ($task->enforceExactSchedule) {
            return $now > $endAtTimestamp;
        }

        // Grace period for unique tasks
        if ($task->delaySeconds === 0 && $this->config->gracePeriodEnabled()) {
            return $now > $endAtTimestamp + $this->config->gracePeriodSeconds();
        }

        return $now > $endAtTimestamp;
    }

    /**
     * Check if a recurring task is ready to run.
     *
     * Validates time window and next run date.
     *
     * @param RecurringTaskRecord $task The recurring task to check
     * @return bool True if the task should run now
     */
    public function shouldRunRecurringNow(RecurringTaskRecord $task): bool
    {
        $now = $this->getCurrentTimestamp();
        $startAt = strtotime($task->startAt);
        $endAt = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;
        $nextRunAt = strtotime($task->nextRunAt);

        if ($now < $startAt || $now > $endAt || $now < $nextRunAt) {
            return false;
        }

        return true;
    }

    /**
     * Check if a unique task should run now (without grace period logic).
     *
     * @param TaskRecord $task The task to check
     * @return bool True if the task should run now
     */
    public function shouldRunTaskNow(TaskRecord $task): bool
    {
        $now = $this->getCurrentTimestamp();
        $startAt = strtotime($task->startAt);
        $endAt = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;

        if ($now < $startAt || $now > $endAt) {
            return false;
        }

        if (!$task->status->isPending()) {
            return false;
        }

        if ($task->attempts >= $task->maxAttempts) {
            return false;
        }

        return true;
    }

    /**
     * Get the delay seconds for a task.
     *
     * @param TaskRecord $task The task
     * @return int Delay in seconds
     */
    public function getDelaySecondsForTask(TaskRecord $task): int
    {
        return $task->delaySeconds;
    }

    /**
     * Calculate the grace period delay for an expired task.
     *
     * @param TaskRecord $task The expired task
     * @return int Number of seconds the task is late
     */
    public function getGracePeriodDelay(TaskRecord $task): int
    {
        if (!$this->isUniqueTaskWithGracePeriod($task)) {
            return 0;
        }

        $endAtTimestamp = $task->endAt ? strtotime($task->endAt) : $this->getCurrentTimestamp();
        $now = $this->getCurrentTimestamp();

        return max(0, $now - $endAtTimestamp);
    }

    /**
     * Check if a unique task qualifies for grace period.
     *
     * @param TaskRecord $task The task to check
     * @return bool True if the task qualifies for grace period
     */
    public function isUniqueTaskWithGracePeriod(TaskRecord $task): bool
    {
        return $task->delaySeconds === 0
            && $this->config->gracePeriodEnabled()
            && !$task->enforceExactSchedule;
    }

    /**
     * Get the current timestamp, respecting Carbon test mocks.
     *
     * @return int Current Unix timestamp
     */
    private function getCurrentTimestamp(): int
    {
        $carbonNow = Carbon::getTestNow();
        if ($carbonNow) {
            return $carbonNow->timestamp;
        }

        return time();
    }
}
