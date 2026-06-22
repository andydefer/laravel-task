<?php

declare(strict_types=1);

namespace AndyDefer\Task\Structs;

use AndyDefer\PhpClient\Abstracts\HydratableStructure;

final class BatchResultTotalStruct extends HydratableStructure
{
    public function __construct(
        public readonly int $success,
        public readonly int $failed,
        public readonly int $processed,
    ) {}
}
