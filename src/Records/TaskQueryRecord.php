<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

/**
 * Query DTO for finding tasks.
 *
 * PURE DATA TRANSFER OBJECT - NO LOGIC.
 */
final class TaskQueryRecord extends AbstractRecord
{
    public function __construct(
        public readonly ?int $limit = null,
        public readonly TaskOrder $order = TaskOrder::OLDEST,
        public readonly bool $include_pending = true,
        public readonly bool $include_recurring = true,
        public readonly bool $include_completed = false,
        public readonly ?Iso8601DateTimeVO $from_date = null,
        public readonly ?Iso8601DateTimeVO $to_date = null,
        public readonly ?TaskSignatureVO $signature = null,
        public readonly ?TaskIdVO $task_id = null,
    ) {}
}
