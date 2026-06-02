<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

final class RecurringResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $signature,
        public readonly bool $success,
    ) {}
}
