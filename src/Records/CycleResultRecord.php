<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

/**
 * Internal DTO for cycle execution results.
 */
final class CycleResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly int $success,
        public readonly int $failed,
        public readonly int $errors,
        public readonly bool $hasErrors,
        public readonly ?string $message = null,
    ) {}
}
