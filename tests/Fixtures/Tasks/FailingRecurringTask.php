<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class FailingRecurringTask extends AbstractRecurringTask
{
    private array $executionLog = [];

    private ?string $failOn = null;

    public function getConfig(): RecurringTaskConfigInterface
    {
        return new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring-failing'),
            description: 'Test recurring task that can fail',
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
        $payload = $this->context->getPayload();
        $data = $payload->toArray();

        $shouldFail = $data['should_fail'] ?? false;
        $failMessage = $data['fail_message'] ?? 'Task failed';

        $this->executionLog[] = [
            'time' => date('c'),
            'payload' => $data,
        ];

        if ($shouldFail || $this->failOn !== null) {
            throw new \RuntimeException($this->failOn ?? $failMessage);
        }
    }
}
