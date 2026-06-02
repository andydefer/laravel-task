<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

/**
 * Record representing a running process information.
 *
 * Stores process ID, task identifier, and start time for a forked process.
 *
 * @author Andy Defer
 */
final class ProcessInfoRecord extends AbstractRecord
{
    public function __construct(
        public readonly int $pid,
        public readonly string $taskIdentifier,
        public readonly int $startedAt,
    ) {}
}
