<?php

// tests/Fixtures/Tasks/TestTask.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;

class TestTask extends AbstractUniqueTask
{
    public bool $beforeCalled = false;

    public bool $processCalled = false;

    public bool $afterCalled = false;

    public bool $afterSuccess = false;

    public ?string $afterError = null;

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
