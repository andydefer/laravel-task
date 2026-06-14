<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Enums\ErrorType;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

/**
 * Error information for a failed recurring task.
 */
final class RecurringTaskErrorRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskSignatureVO $signature,
        public readonly ErrorType $error_type,
        public readonly ?string $details = null,
    ) {}
}
