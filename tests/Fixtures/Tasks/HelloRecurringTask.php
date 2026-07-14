<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;

/**
 * Fixture for testing recurring tasks.
 *
 * Writes "Hello World, I am a recurring task" to the console.
 */
final class HelloRecurringTask extends AbstractRecurringTask
{
    protected function process(): void
    {
        $this->info(new DescriptionVO('Hello World, I am a recurring task'));
    }

    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        // No action needed for fixture
    }
}
