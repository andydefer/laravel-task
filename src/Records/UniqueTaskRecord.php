<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;

final class UniqueTaskRecord extends AbstractRecord
{
    public function __construct(
        public readonly ?TaskIdVO $id = null,
        public readonly ?TaskAliasVO $alias = null,
        public readonly ?UniqueTaskFqcnVO $fqcn = null,
        public readonly ?StrictDataObject $payload = null,
        public readonly ?Iso8601DateTimeVO $scheduled_at = null,
        public readonly ?DurationVO $grace_period_seconds = new DurationVO(86400),
        public readonly ?UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        public readonly ?CounterVO $attempts = new CounterVO(0),
        public readonly ?MaxFailedAttemptsVO $max_attempts = new MaxFailedAttemptsVO(3),
        public readonly ?Iso8601DateTimeVO $finished_at = null,
    ) {}
}
