<?php

declare(strict_types=1);

namespace AndyDefer\Task\Structs;

use AndyDefer\PhpClient\Abstracts\HydratableStructure;

final class CycleResultStruct extends HydratableStructure
{
    public function __construct(
        public readonly int $number,
        public readonly string $started_at,
        public readonly int $success,
        public readonly int $failed,
        public readonly int $errors,
        public readonly float $duration,
        public readonly bool $has_errors,
    ) {}
}
