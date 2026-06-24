<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;

final class TaskExecutionDebugFiltersRecord extends AbstractRecord
{
    public function __construct(
        public readonly ?TaskAliasVO $alias = null,
        public readonly ?TaskFqcnVO $fqcn = null,
        public readonly ?ExecutionStatus $status = null,
        public readonly ?Iso8601DateTimeVO $started_at_from = null,
        public readonly ?Iso8601DateTimeVO $started_at_to = null,
        public readonly ?Iso8601DateTimeVO $ended_at_from = null,
        public readonly ?Iso8601DateTimeVO $ended_at_to = null,
        public readonly ?bool $include_deleted = false,
    ) {}
}
