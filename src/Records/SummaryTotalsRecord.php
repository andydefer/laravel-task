<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

/**
 * Record representing total summary counts.
 */
final class SummaryTotalsRecord extends AbstractRecord
{
    public function __construct(
        public readonly int $success,
        public readonly int $failed,
        public readonly int $errors,
    ) {}
}
