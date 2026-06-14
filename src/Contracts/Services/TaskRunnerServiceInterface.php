<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;

interface TaskRunnerServiceInterface
{
    /**
     * Executes a unique task.
     */
    public function runTask(TaskRecord $task): bool;

    /**
     * Executes a recurring task.
     */
    public function runRecurringTask(RecurringTaskRecord $task): bool;
}
