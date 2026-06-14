<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Records\TaskRecord;

/**
 * Type-safe collection for unique TaskRecord instances.
 *
 * @extends AbstractTypedCollection<TaskRecord>
 */
final class TaskRecordCollection extends AbstractTypedCollection
{
    /**
     * Initialize an empty collection for TaskRecord instances.
     */
    public function __construct()
    {
        parent::__construct(TaskRecord::class);
    }

    /**
     * Get all tasks with a specific status.
     *
     * @return self Collection filtered by status
     */
    public function filterByStatus(string $status): self
    {
        $filtered = new self;

        foreach ($this as $task) {
            if ($task->status->value === $status) {
                $filtered->add($task);
            }
        }

        return $filtered;
    }

    /**
     * Get tasks that have exceeded their max attempts.
     *
     * @return self Collection of failed tasks
     */
    public function getFailedTasks(): self
    {
        $failed = new self;

        foreach ($this as $task) {
            if ($task->attempts >= $task->max_attempts) {
                $failed->add($task);
            }
        }

        return $failed;
    }
}
