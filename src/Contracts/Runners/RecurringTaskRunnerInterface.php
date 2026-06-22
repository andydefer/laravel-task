<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Runners;

use AndyDefer\Task\Records\ExecutionResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;

interface RecurringTaskRunnerInterface
{
    public function run(RecurringTaskRecord $record): ExecutionResultRecord;
}
