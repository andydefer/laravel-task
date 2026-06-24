<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;

/**
 * Internal DTO for cycle execution results.
 */
final class CycleResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly CounterVO $success,
        public readonly CounterVO $failed,
        public readonly CounterVO $errors,
        public readonly bool $hasErrors,
        public readonly ?DescriptionVO $message = null,
    ) {}
}
