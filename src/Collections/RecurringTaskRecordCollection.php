<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Records\RecurringTaskRecord;

final class RecurringTaskRecordCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(RecurringTaskRecord::class);
    }
}
