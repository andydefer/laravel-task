<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;

final class TestUniqueTaskWithCustomConfig extends AbstractUniqueTask
{
    protected function process(): void
    {
        $this->info('Processing with custom config');
    }
}
