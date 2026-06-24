<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;

final class TaskExecutionDebugRecord extends AbstractRecord
{
    public function __construct(
        public readonly UuidVO $id,
        public readonly ?TaskAliasVO $alias,
        public readonly ?TaskFqcnVO $fqcn,
        public readonly ?ExecutionStatus $status,
        public readonly ?Iso8601DateTimeVO $started_at,
        public readonly ?Iso8601DateTimeVO $ended_at,
        public readonly ?StrictDataObject $data,
    ) {}
}
