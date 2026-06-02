<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Records\RecurringTaskRecord;

/**
 * Type-safe collection for recurring TaskRecord instances.
 *
 * @extends AbstractTypedCollection<RecurringTaskRecord>
 */
final class RecurringTaskRecordCollection extends AbstractTypedCollection
{
    /**
     * Initialize an empty collection for RecurringTaskRecord instances.
     */
    public function __construct()
    {
        parent::__construct(RecurringTaskRecord::class);
    }

    /**
     * Get all recurring tasks that are ready to run based on next_run_at.
     *
     * @return self Collection of runnable tasks
     */
    public function getRunnableTasks(): self
    {
        $now = time();
        $runnable = new self;

        foreach ($this as $task) {
            $nextRunAt = strtotime($task->nextRunAt);
            if ($nextRunAt <= $now) {
                $runnable->add($task);
            }
        }

        return $runnable;
    }

    /**
     * Get tasks that have expired (end_at passed).
     *
     * @return self Collection of expired tasks
     */
    public function getExpiredTasks(): self
    {
        $now = time();
        $expired = new self;

        foreach ($this as $task) {
            if ($task->endAt !== null) {
                $endAt = strtotime($task->endAt);
                if ($endAt <= $now) {
                    $expired->add($task);
                }
            }
        }

        return $expired;
    }

    /**
     * Find a recurring task by its signature.
     */
    public function findBySignature(string $signature): ?RecurringTaskRecord
    {
        foreach ($this as $task) {
            if ($task->signature === $signature) {
                return $task;
            }
        }

        return null;
    }
}
