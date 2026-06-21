<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Records\TaskExecutionDebugRecord;

final class TaskExecutionDebugRecordCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(TaskExecutionDebugRecord::class);
    }

    public function filterByStatus(string $status): self
    {
        $filtered = new self;
        foreach ($this as $record) {
            if ($record->status->value === $status) {
                $filtered->add($record);
            }
        }

        return $filtered;
    }

    public function getSucceeded(): self
    {
        return $this->filterByStatus('succeeded');
    }

    public function getFailed(): self
    {
        return $this->filterByStatus('failed');
    }
}
