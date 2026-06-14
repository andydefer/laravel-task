<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;

final class UniqueResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskIdVO $task_id,
        public readonly bool $success,
    ) {}
}
