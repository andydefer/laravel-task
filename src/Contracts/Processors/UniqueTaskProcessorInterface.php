<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Processors;

use AndyDefer\Task\Records\ProcessResultRecord;

interface UniqueTaskProcessorInterface
{
    public function process(?int $limit = null): ProcessResultRecord;
}
