<?php

// tests/Fixtures/Tasks/TestTask.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

class TestTask extends AbstractTask
{
    public bool $beforeCalled = false;

    public bool $processCalled = false;

    public bool $afterCalled = false;

    public bool $afterSuccess = false;

    public ?string $afterError = null;

    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('test-task'),
            description: 'Test task for unit tests',
            delay_seconds: new CounterVO(0),
            max_attempts: new CounterVO(3),
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
