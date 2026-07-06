<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use RuntimeException;

/**
 * Fixture task for testing recurring task execution.
 *
 * Provides configurable behavior for testing success and failure scenarios,
 * with detailed logging of execution steps and lifecycle hooks.
 */
final class TestRecurringTask extends AbstractRecurringTask
{
    /**
     * @var array<array{time: string, payload: array<string, mixed>}> Log of all executions
     */
    private array $executionLog = [];

    /**
     * @var DescriptionVO|null Error message that will trigger a failure when set
     */
    private ?DescriptionVO $failureTrigger = null;

    /**
     * @var bool Indicates whether the before() hook was called
     */
    private bool $beforeWasCalled = false;

    /**
     * @var bool Indicates whether the after() hook was called
     */
    private bool $afterWasCalled = false;

    /**
     * @var DescriptionVO|null Error passed to the after() hook
     */
    private ?DescriptionVO $afterErrorMessage = null;

    /**
     * Configures the task to fail during processing.
     *
     * @param  string  $message  The error message to throw during execution
     */
    public function setFailureTrigger(string $message): void
    {
        $this->failureTrigger = new DescriptionVO($message);
    }

    /**
     * Returns the complete execution log.
     *
     * @return array<array{time: string, payload: array<string, mixed>}> Log entries
     */
    public function getExecutionLog(): array
    {
        return $this->executionLog;
    }

    /**
     * Checks if the before() hook was called.
     *
     * @return bool True if before() was called during execution
     */
    public function wasBeforeCalled(): bool
    {
        return $this->beforeWasCalled;
    }

    /**
     * Checks if the after() hook was called.
     *
     * @return bool True if after() was called during execution
     */
    public function wasAfterCalled(): bool
    {
        return $this->afterWasCalled;
    }

    /**
     * Returns the error passed to the after() hook.
     *
     * @return DescriptionVO|null The error message or null if no error occurred
     */
    public function getAfterErrorMessage(): ?DescriptionVO
    {
        return $this->afterErrorMessage;
    }

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
     * Records that the after hook was executed and stores any error.
     */
    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        $this->afterWasCalled = true;
        $this->afterErrorMessage = $error;
    }

    /**
     * {@inheritDoc}
     *
     * Executes the task logic and logs execution details.
     *
     * @throws RuntimeException When failureTrigger is set
     */
    protected function process(): void
    {
        $this->executionLog[] = [
            'time' => (new Iso8601DateTimeVO)->getValue(),
            'payload' => $this->context->getPayload()->toArray(),
        ];

        if ($this->failureTrigger !== null) {
            throw new RuntimeException($this->failureTrigger->getValue());
        }
    }
}
