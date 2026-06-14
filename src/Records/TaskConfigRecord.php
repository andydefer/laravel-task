<?php

// src/Records/TaskConfigRecord.php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class TaskConfigRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskSignatureVO $signature,
        public readonly string $description,
        public readonly CounterVO $delay_seconds = new CounterVO(300),
        public readonly CounterVO $max_attempts = new CounterVO(3),
        public readonly ?Iso8601DateTimeVO $start_at = null,
        public readonly ?Iso8601DateTimeVO $end_at = null,
    ) {}
}
