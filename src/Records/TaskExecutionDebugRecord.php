<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

final class TaskExecutionDebugRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $task_type,
        public readonly string $task_identifier,
        public readonly StrictDataObject $data,
    ) {}
}
