<?php

declare(strict_types=1);

namespace AndyDefer\Task\Structs;

use AndyDefer\PhpClient\Abstracts\HydratableStructure;
use AndyDefer\Task\Collections\TaskErrorStructCollection;

final class BatchResultDetailsStruct extends HydratableStructure
{
    public function __construct(
        public readonly int $success,
        public readonly int $failed,
        public readonly int $total,
        public readonly TaskErrorStructCollection $errors,
    ) {}
}
