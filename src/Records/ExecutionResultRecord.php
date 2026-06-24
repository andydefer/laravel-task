<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\MillisecondsVO;

final class ExecutionResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly bool $success,
        public readonly ?TaskErrorRecord $error,
        public readonly MillisecondsVO $execution_time,
    ) {}
}
