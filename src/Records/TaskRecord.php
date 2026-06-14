<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

/**
 * Task DTO for unique tasks.
 *
 * PURE DATA TRANSFER OBJECT - NO LOGIC.
 */
final class TaskRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskIdVO $id,
        public readonly TaskSignatureVO $signature,
        public readonly string $class,
        public readonly TaskPayloadRecord $payload,
        public readonly TaskStatus $status = TaskStatus::PENDING,
        public readonly ?Iso8601DateTimeVO $created_at = null,
        public readonly ?Iso8601DateTimeVO $start_at = null,
        public readonly ?Iso8601DateTimeVO $end_at = null,
        public readonly CounterVO $delay_seconds = new CounterVO(0),
        public readonly CounterVO $attempts = new CounterVO(0),
        public readonly CounterVO $max_attempts = new CounterVO(3),
        public readonly ?string $last_error = null,
        public readonly bool $enforce_exact_schedule = false,
    ) {}
}
