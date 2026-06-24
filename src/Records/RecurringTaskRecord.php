<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class RecurringTaskRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskSignatureVO $alias,
        public readonly RecurringTaskFqcnVO $fqcn,
        public readonly StrictDataObject $payload,
        public readonly CounterVO $interval_seconds,
        public readonly ?Iso8601DateTimeVO $start_at = null,
        public readonly ?Iso8601DateTimeVO $end_at = null,
        public readonly RecurringTaskStatus $status = RecurringTaskStatus::WAITING,
        public readonly ?Iso8601DateTimeVO $last_run_at = null,
        public readonly ?Iso8601DateTimeVO $finished_at = null,
        public readonly ?Iso8601DateTimeVO $cancelled_at = null,
        public readonly CounterVO $failed_attempts = new CounterVO(0),
        public readonly MaxFailedAttemptsVO $max_failed_attempts = new MaxFailedAttemptsVO(3),

    ) {}
}
