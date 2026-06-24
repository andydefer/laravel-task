<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;

final class UniqueTaskFiltersRecord extends AbstractRecord
{
    public function __construct(
        public readonly ?TaskIdVO $id = null,
        public readonly ?TaskAliasVO $alias = null,
        public readonly ?UniqueTaskFqcnVO $fqcn = null,
        public readonly ?UniqueTaskStatus $status = null,
        public readonly ?Iso8601DateTimeVO $scheduled_at_from = null,
        public readonly ?Iso8601DateTimeVO $scheduled_at_to = null,
        public readonly ?Iso8601DateTimeVO $finished_at_from = null,
        public readonly ?Iso8601DateTimeVO $finished_at_to = null,
        public readonly ?CounterVO $attempts = null,
        public readonly ?MaxFailedAttemptsVO $max_attempts = null,
        public readonly ?bool $include_deleted = false,
    ) {}
}
