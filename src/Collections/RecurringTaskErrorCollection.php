<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Records\RecurringTaskErrorRecord;

final class RecurringTaskErrorCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(RecurringTaskErrorRecord::class);
    }
}
