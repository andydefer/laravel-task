<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\Task\Records\BatchResultRecord;

interface TaskBatchServiceInterface
{
    /**
     * Processes all available tasks in the current batch.
     */
    public function process(?int $limit = null): BatchResultRecord;

    /**
     * Processes only unique (non-recurring) tasks in the batch.
     */
    public function processUniqueOnly(?int $limit = null): BatchResultRecord;

    /**
     * Processes only recurring tasks in the batch.
     */
    public function processRecurringOnly(?int $limit = null): BatchResultRecord;
}
