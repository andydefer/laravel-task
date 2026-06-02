<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

final class UniqueResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $id,
        public readonly bool $success,
    ) {}
}
