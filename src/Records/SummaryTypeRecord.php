<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

/**
 * Record representing summary counts for a specific task type.
 */
final class SummaryTypeRecord extends AbstractRecord
{
    public function __construct(
        public readonly int $success,
        public readonly int $failed,
    ) {}
}
