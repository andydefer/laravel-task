<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use RuntimeException;

/**
 * Fixture task that always fails during execution.
 *
 * Used for testing error handling, logging, and lifecycle hooks
 * in the task execution system.
 */
class FailingTask extends AbstractUniqueTask
{
    /**
     * @var bool Indicates whether the before() hook was called
     */
    public bool $beforeWasCalled = false;

    /**
     * @var bool Indicates whether the after() hook was called
     */
    public bool $afterWasCalled = false;

    /**
     * @var bool The success flag passed to the after() hook
     */
    public bool $afterExecutedSuccessfully = false;

    /**
     * @var DescriptionVO|null The error description passed to the after() hook
     */
    public ?DescriptionVO $afterErrorMessage = null;

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException Always throws an exception to simulate failure
     */
    protected function process(): void
    {
        throw new RuntimeException('Test exception');
    }

    /**
     * {@inheritDoc}
     *
     * Records the after() hook call state for test assertions.
     */
    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        $this->afterWasCalled = true;
        $this->afterExecutedSuccessfully = $success;
        $this->afterErrorMessage = $error;
    }
}
