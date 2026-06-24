<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;

final class TaskErrorRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskAliasVO $alias,
        public readonly TaskFqcnVO $fqcn,
        public readonly DescriptionVO $error,
        public readonly ?DescriptionVO $context = null,
    ) {}
}
