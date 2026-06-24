<?php

// tests/Fixtures/Tasks/FailingTask.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;

class FailingTask extends AbstractUniqueTask
{
    public bool $afterCalled = false;

    public bool $afterSuccess = false;

    public ?string $afterError = null;

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
