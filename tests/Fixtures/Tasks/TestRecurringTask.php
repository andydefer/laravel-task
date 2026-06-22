<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class TestRecurringTask extends AbstractRecurringTask
{
    private array $executionLog = [];

    private ?string $failOn = null;

    public function getConfig(): RecurringTaskConfigInterface
    {
        return new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring'),
            description: 'Test recurring task',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            max_attempts: new CounterVO(3),
        );
    }

    public function setFailOn(string $message): void
    {
        $this->failOn = $message;
    }

    public function getExecutionLog(): array
    {
        return $this->executionLog;
    }

    protected function process(): void
    {
        $this->executionLog[] = [
            'time' => date('c'),
            'payload' => $this->context->getPayload()->toArray(),
        ];

        if ($this->failOn !== null) {
            throw new \RuntimeException($this->failOn);
        }
    }
}
