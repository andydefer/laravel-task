<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

final class ExecutionResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly bool $success,
        public readonly ?TaskErrorRecord $error = null,
        public readonly float $execution_time = 0.0,
    ) {}
}
