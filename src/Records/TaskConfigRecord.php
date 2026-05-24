<?php

// src/Records/TaskConfigRecord.php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\Records\AbstractRecord;

final class TaskConfigRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $signature,
        public readonly string $description,
        public readonly int $delaySeconds = 300,
        public readonly int $maxAttempts = 3,
        public readonly ?string $startAt = null,
        public readonly ?string $endAt = null,
    ) {}
}
