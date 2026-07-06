<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Traits\Hydratable;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;

final class UniqueTaskConfigRecord extends AbstractRecord
{
    use Hydratable;

    public function __construct(
        public readonly DescriptionVO $description,
        public readonly Iso8601DateTimeVO $scheduled_at,
        public readonly MaxFailedAttemptsVO $max_attempts = new MaxFailedAttemptsVO(3),
        public readonly DurationVO $grace_period = new DurationVO(86400),
    ) {}
}
