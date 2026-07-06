<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Traits\Hydratable;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;

final class RecurringTaskConfigRecord extends AbstractRecord
{
    use Hydratable;

    public function __construct(
        public readonly DescriptionVO $description,
        public readonly CounterVO $interval_seconds,
        public readonly ?Iso8601DateTimeVO $start_at = null,
        public readonly ?Iso8601DateTimeVO $end_at = null,
        public readonly MaxFailedAttemptsVO $max_attempts = new MaxFailedAttemptsVO(3),
    ) {}
}
