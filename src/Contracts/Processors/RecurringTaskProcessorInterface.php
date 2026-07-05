<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Processors;

use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\ValueObjects\LimitVO;

interface RecurringTaskProcessorInterface
{
    public function process(LimitVO $limit = new LimitVO): ProcessResultRecord;
}
