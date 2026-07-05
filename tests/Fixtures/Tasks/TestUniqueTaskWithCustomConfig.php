<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;

final class TestUniqueTaskWithCustomConfig extends AbstractUniqueTask
{
    protected function process(): void
    {
        $this->info(DescriptionVO::from('Processing with custom config'));
    }
}
