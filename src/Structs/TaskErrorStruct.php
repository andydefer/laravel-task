<?php

declare(strict_types=1);

namespace AndyDefer\Task\Structs;

use AndyDefer\PhpClient\Abstracts\HydratableStructure;

final class TaskErrorStruct extends HydratableStructure
{
    public function __construct(
        public readonly string $alias,
        public readonly string $fqcn,
        public readonly string $error,
        public readonly ?string $context = null,
    ) {}
}
