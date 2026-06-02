<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts;

use AndyDefer\Task\Services\BatchResult;

interface TaskProcessorInterface
{
    public function process(): BatchResult;

    public function processUniqueOnly(): BatchResult;

    public function processRecurringOnly(): BatchResult;
}
