<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;

final class FailingRecurringTaskForProcessor extends AbstractRecurringTask
{
    protected function process(): void
    {
        throw new \RuntimeException('Test exception');
    }
}
