<?php

declare(strict_types=1);

namespace AndyDefer\Task\Structs;

use AndyDefer\PhpClient\Abstracts\HydratableStructure;
use AndyDefer\Task\Collections\TaskErrorStructCollection;

final class UniqueBatchResultStruct extends HydratableStructure
{
    public function __construct(
        public readonly string $started_at,
        public readonly string $ended_at,
        public readonly int $duration_ms,
        public readonly int $success,
        public readonly int $failed,
        public readonly int $total,
        public readonly TaskErrorStructCollection $errors,
        public readonly bool $has_failures,
    ) {}
}
