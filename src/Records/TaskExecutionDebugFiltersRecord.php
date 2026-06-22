<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class TaskExecutionDebugFiltersRecord extends AbstractRecord
{
    public function __construct(
        public readonly ?string $task_type = null,
        public readonly ?string $task_identifier = null,
        public readonly ?ExecutionStatus $status = null,
        public readonly ?Iso8601DateTimeVO $acted_at_from = null,
        public readonly ?Iso8601DateTimeVO $acted_at_to = null,
    ) {}
}
