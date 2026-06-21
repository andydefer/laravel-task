<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Records\UniqueTaskRecord;

final class UniqueTaskRecordCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(UniqueTaskRecord::class);
    }

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
}
