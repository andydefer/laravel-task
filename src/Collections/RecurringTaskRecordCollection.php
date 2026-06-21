<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Records\RecurringTaskRecord;

final class RecurringTaskRecordCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(RecurringTaskRecord::class);
    }

    public function getExpiredTasks(string $now): self
    {
        $expired = new self;
        foreach ($this as $task) {
            if ($task->end_at !== null && $task->end_at->value <= $now) {
                $expired->add($task);
            }
        }

        return $expired;
    }
}
