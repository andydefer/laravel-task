<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Configs\UniqueTaskConfig;
use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Support\Carbon;

final class TestUniqueTask extends AbstractUniqueTask
{
    private array $executionLog = [];

    private ?string $failOn = null;

    public function getConfig(): UniqueTaskConfigInterface
    {
        return new UniqueTaskConfig(
            alias: new TaskSignatureVO('test-unique'),
            description: 'Test unique task',
            scheduled_at: new Iso8601DateTimeVO(Carbon::now()->addMinutes(5)->toIso8601String()),
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
            'time' => Carbon::now()->toIso8601String(),
            'payload' => $this->context->getPayload()->toArray(),
        ];

        if ($this->failOn !== null) {
            throw new \RuntimeException($this->failOn);
        }
    }
}
