<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;

/**
 * Fixture for testing unique tasks.
 *
 * Writes "Hello World, I am a unique task" to the console.
 */
final class HelloUniqueTask extends AbstractUniqueTask
{
    protected function before(StrictDataObject $payload): void
    {
        // No validation needed for fixture
    }

    protected function process(): void
    {
        $this->info(new DescriptionVO('Hello World, I am a unique task'));
    }

    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        // No action needed for fixture
    }
}
