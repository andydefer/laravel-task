<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\RecurringTaskResultRecord;
use AndyDefer\Task\Records\UniqueTaskResultRecord;

interface BatchResultServiceInterface
{
    /**
     * Adds a unique task result to the batch.
     */
    public function withUniqueTask(BatchResultRecord $record, UniqueTaskResultRecord $result): BatchResultRecord;

    /**
     * Adds a recurring task result to the batch.
     */
    public function withRecurringTask(BatchResultRecord $record, RecurringTaskResultRecord $result): BatchResultRecord;
}
