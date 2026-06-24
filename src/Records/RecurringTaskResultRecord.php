<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;

final class RecurringTaskResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskAliasVO $alias,
        public readonly bool $success,
        public readonly ?DescriptionVO $error = null,
    ) {}
}
