<?php

// src/Services/TaskValidator.php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;

class TaskValidator
{
    public function validateTaskClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        $instance = new $className();

        return $instance instanceof AbstractTask;
    }

    public function canRunTask(TaskRecord $task): bool
    {
        if (!$task->status->isPending()) {
            return false;
        }

        if ($task->attempts >= $task->maxAttempts) {
            return false;
        }

        $startAtTimestamp = strtotime($task->startAt);
        if (time() < $startAtTimestamp) {
            return false;
        }

        $endAtTimestamp = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;
        if (time() > $endAtTimestamp) {
            return false;
        }

        return true;
    }

    public function isTaskExpired(TaskRecord $task): bool
    {
        $endAtTimestamp = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;

        return time() > $endAtTimestamp;
    }

    public function shouldRunRecurringNow(RecurringTaskRecord $task): bool
    {
        $now = time();
        $startAt = strtotime($task->startAt);
        $endAt = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;
        $nextRunAt = strtotime($task->nextRunAt);

        if ($now < $startAt || $now > $endAt || $now < $nextRunAt) {
            return false;
        }

        return true;
    }

    public function shouldRunTaskNow(TaskRecord $task): bool
    {
        $now = time();
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
}
