<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class TestRecurringTask extends AbstractRecurringTask
{
    private array $executionLog = [];

    private ?DescriptionVO $failOn = null;

    private bool $beforeCalled = false;

    private bool $afterCalled = false;

    private ?DescriptionVO $afterError = null;

    public function setFailOn(string $message): void
    {
        $this->failOn = new DescriptionVO($message);
    }

    public function getExecutionLog(): array
    {
        return $this->executionLog;
    }

    public function wasBeforeCalled(): bool
    {
        return $this->beforeCalled;
    }

    public function wasAfterCalled(): bool
    {
        return $this->afterCalled;
    }

    public function getAfterError(): ?DescriptionVO
    {
        return $this->afterError;
    }

    protected function before(): void
    {
        $this->beforeCalled = true;
    }

    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        $this->afterCalled = true;
        $this->afterError = $error;
    }

    protected function process(): void
    {
        $this->executionLog[] = [
            'time' => (new Iso8601DateTimeVO)->getValue(),
            'payload' => $this->context->getPayload()->toArray(),
        ];

        if ($this->failOn !== null) {
            throw new \RuntimeException($this->failOn->getValue());
        }
    }
}
