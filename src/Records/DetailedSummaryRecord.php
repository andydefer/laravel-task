<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

/**
 * Record representing a detailed summary of all aggregated results.
 *
 * Provides a breakdown of success and failure counts by task type.
 */
final class DetailedSummaryRecord extends AbstractRecord
{
    public function __construct(
        public readonly SummaryTotalsRecord $total,
        public readonly SummaryTypeRecord $unique,
        public readonly SummaryTypeRecord $recurring,
    ) {}
}
