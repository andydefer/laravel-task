<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

final class TaskErrorRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $error,
        public readonly ?string $context = null,
    ) {}
}
