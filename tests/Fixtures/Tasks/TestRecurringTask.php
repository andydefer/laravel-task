<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use Illuminate\Support\Carbon;

final class TestRecurringTask extends AbstractRecurringTask
{
    private array $executionLog = [];

    private ?string $failOn = null;

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
            'time' => Carbon::now()->toIso8601String(),
            'payload' => $this->context->getPayload()->toArray(),
        ];

        if ($this->failOn !== null) {
            throw new \RuntimeException($this->failOn);
        }
    }
}
