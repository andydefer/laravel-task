<?php

// src/Collections/TaskCollection.php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\Records\Collections\TypedCollection;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;

final class TaskCollection extends TypedCollection
{
    public function __construct()
    {
        parent::__construct(TaskRecord::class, RecurringTaskRecord::class);
    }

    public function getPendingTasks(): self
    {
        $collection = new self;
        foreach ($this->items as $task) {
            if ($task instanceof TaskRecord && $task->status->isPending()) {
                $collection->add($task);
            }
        }

        return $collection;
    }

    public function getRecurringTasks(): self
    {
        $collection = new self;
        foreach ($this->items as $task) {
            if ($task instanceof RecurringTaskRecord) {
                $collection->add($task);
            }
        }

        return $collection;
    }

    public function getUniqueTasks(): self
    {
        $collection = new self;
        foreach ($this->items as $task) {
            if ($task instanceof TaskRecord) {
                $collection->add($task);
            }
        }

        return $collection;
    }
}
