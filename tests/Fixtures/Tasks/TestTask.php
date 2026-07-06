<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;

/**
 * Fixture task for testing unique task execution.
 *
 * Provides a simple implementation that records all lifecycle hook calls
 * for verification in unit tests.
 */
class TestTask extends AbstractUniqueTask
{
    /**
     * @var bool Indicates whether the before() hook was called
     */
    public bool $beforeWasCalled = false;

    /**
     * @var bool Indicates whether the process() method was called
     */
    public bool $processWasCalled = false;

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
     * Records that the before hook was executed.
     *
     * @param  StrictDataObject  $payload  The input data for the task
     */
    protected function before(StrictDataObject $payload): void
    {
        $this->beforeWasCalled = true;
    }

    /**
     * {@inheritDoc}
     *
     * Records that the process method was executed.
     */
    protected function process(): void
    {
        $this->processWasCalled = true;
    }

    /**
     * {@inheritDoc}
     *
     * Records that the after hook was executed and stores the result.
     */
    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        $this->afterWasCalled = true;
        $this->afterExecutedSuccessfully = $success;
        $this->afterErrorMessage = $error;
    }
}
