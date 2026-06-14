<?php

// tests/Fixtures/Tasks/FailingTask.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

class FailingTask extends AbstractTask
{
    public bool $afterCalled = false;

    public bool $afterSuccess = false;

    public ?string $afterError = null;

    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('failing-task'),
            description: 'Task that always fails',
            delay_seconds: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );
    }

    protected function process(): void
    {
        $this->context->getLaravelApp()->make(HydrationService::class);
        throw new \RuntimeException('Test exception');
    }

    protected function after(bool $success, ?string $error = null): void
    {
        $this->afterCalled = true;
        $this->afterSuccess = $success;
        $this->afterError = $error;
    }
}
