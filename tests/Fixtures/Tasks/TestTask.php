<?php

// tests/Fixtures/Tasks/TestTask.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Configs\UniqueTaskConfig;
use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

class TestTask extends AbstractUniqueTask
{
    public bool $beforeCalled = false;

    public bool $processCalled = false;

    public bool $afterCalled = false;

    public bool $afterSuccess = false;

    public ?string $afterError = null;

    public function getConfig(): UniqueTaskConfigInterface
    {
        return new UniqueTaskConfig(
            alias: new TaskSignatureVO('test-task'),
            description: 'Test task for unit tests',
            scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(5)->toIso8601String()),
            max_attempts: new MaxFailedAttemptsVO(3),
        );
    }

    protected function before(): void
    {
        $this->beforeCalled = true;
    }

    protected function process(): void
    {
        $this->processCalled = true;
    }

    protected function after(bool $success, ?string $error = null): void
    {
        $this->afterCalled = true;
        $this->afterSuccess = $success;
        $this->afterError = $error;
    }
}
