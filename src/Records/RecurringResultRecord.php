<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\TaskAliasVO;

final class RecurringResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskAliasVO $alias,
        public readonly bool $success,
    ) {}
}
