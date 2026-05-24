<?php

// src/Records/TaskRecord.php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\Records\AbstractRecord;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;

final class TaskRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $id,
        public readonly string $signature,
        public readonly string $class,
        public readonly TaskPayloadRecord $payload,
        public readonly TaskMode $mode,
        public readonly TaskStatus $status,
        public readonly string $createdAt,
        public readonly string $startAt,
        public readonly ?string $endAt,
        public readonly int $delaySeconds,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?string $lastError = null,
        public readonly bool $enforceExactSchedule = false,  // ← NOUVEAU
    ) {}
}
