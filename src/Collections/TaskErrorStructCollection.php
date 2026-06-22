<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Structs\TaskErrorStruct;

/**
 * @extends AbstractTypedCollection<TaskErrorStruct>
 */
final class TaskErrorStructCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(TaskErrorStruct::class);
    }
}
