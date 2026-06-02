<?php

// src/Collections/TaskCollection.php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;

final class TaskCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(TaskRecord::class, RecurringTaskRecord::class);
    }
}
