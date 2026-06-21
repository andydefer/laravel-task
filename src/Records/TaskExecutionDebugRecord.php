<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class TaskExecutionDebugRecord extends AbstractRecord
{
    public function __construct(
        public readonly Iso8601DateTimeVO $acted_at,
        public readonly ExecutionStatus $status,
        public readonly string $info,
    ) {}
}
