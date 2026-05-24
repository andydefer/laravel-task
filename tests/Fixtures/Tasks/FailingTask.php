<?php

// tests/Fixtures/Tasks/FailingTask.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Records\TaskConfigRecord;

class FailingTask extends AbstractTask
{
    public bool $afterCalled = false;
    public bool $afterSuccess = false;
    public ?string $afterError = null;

    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'failing-task',
            description: 'Task that always fails',
            delaySeconds: 0,
            maxAttempts: 3,
        );
    }

    protected function process(): void
    {
        throw new \RuntimeException('Test exception');
    }

    protected function after(bool $success, ?string $error = null): void
    {
        $this->afterCalled = true;
        $this->afterSuccess = $success;
        $this->afterError = $error;
    }
}
