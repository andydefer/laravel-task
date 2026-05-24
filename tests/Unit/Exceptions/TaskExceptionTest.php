<?php

// tests/Unit/Exceptions/TaskExceptionTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Exceptions;

use AndyDefer\Task\Exceptions\TaskException;
use AndyDefer\Task\Tests\UnitTestCase;

final class TaskExceptionTest extends UnitTestCase
{
    public function test_task_not_found_creates_exception_with_message(): void
    {
        $exception = TaskException::taskNotFound('123');

        $this->assertInstanceOf(TaskException::class, $exception);
        $this->assertStringContainsString('123', $exception->getMessage());
    }

    public function test_class_not_found_creates_exception_with_message(): void
    {
        $exception = TaskException::classNotFound('App\\Tasks\\NonExistentTask');

        $this->assertInstanceOf(TaskException::class, $exception);
        $this->assertStringContainsString('App\\Tasks\\NonExistentTask', $exception->getMessage());
    }

    public function test_invalid_class_creates_exception_with_message(): void
    {
        $exception = TaskException::invalidClass('stdClass');

        $this->assertInstanceOf(TaskException::class, $exception);
        $this->assertStringContainsString('stdClass', $exception->getMessage());
    }

    public function test_recurring_already_exists_creates_exception_with_message(): void
    {
        $exception = TaskException::recurringAlreadyExists('test-signature');

        $this->assertInstanceOf(TaskException::class, $exception);
        $this->assertStringContainsString('test-signature', $exception->getMessage());
    }
}
