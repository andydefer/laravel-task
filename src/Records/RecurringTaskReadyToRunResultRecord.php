<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;

final class RecurringTaskReadyToRunResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly RecurringTaskRecordCollection $tasks,
        public readonly FreshStateResultRecord $fresh_state,
    ) {}
}
