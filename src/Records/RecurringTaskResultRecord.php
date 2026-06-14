<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class RecurringTaskResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskSignatureVO $signature,
        public readonly bool $success,
        public readonly ?string $error = null,
    ) {}
}
