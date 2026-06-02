<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Records\RecurringResultRecord;

final class RecurringResultCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(RecurringResultRecord::class);
    }
}
