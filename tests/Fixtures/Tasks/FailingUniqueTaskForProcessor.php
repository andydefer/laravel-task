<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;

final class FailingUniqueTaskForProcessor extends AbstractUniqueTask
{
    protected function process(): void
    {
        throw new \RuntimeException('Test exception');
    }
}
