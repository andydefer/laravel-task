<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;

final class LoopResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly CounterVO $cycle_count,
        public readonly CounterVO $total_success,
        public readonly CounterVO $total_failed,
        public readonly CounterVO $total_errors,
        public readonly bool $has_errors,
        public readonly ?DescriptionVO $last_exception
    ) {}
}
