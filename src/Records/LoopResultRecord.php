<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;

final class LoopResultRecord
{
    public function __construct(
        public readonly CounterVO $cycleCount,
        public readonly CounterVO $totalSuccess,
        public readonly CounterVO $totalFailed,
        public readonly CounterVO $totalErrors,
        public readonly bool $hasErrors,
        public readonly ?DescriptionVO $lastException
    ) {}
}
