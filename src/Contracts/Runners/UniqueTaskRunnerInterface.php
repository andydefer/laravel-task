<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Runners;

use AndyDefer\Task\Records\ExecutionResultRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;

interface UniqueTaskRunnerInterface
{
    public function run(UniqueTaskRecord $record): ExecutionResultRecord;
}
