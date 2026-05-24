<?php

// tests/Unit/TaskServiceProviderTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit;

use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Directives\RunTaskDirective;
use AndyDefer\Task\Services\TaskRegistry;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class TaskServiceProviderTest extends IntegrationTestCase
{
    public function test_task_storage_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskStorage::class));

        $first = $this->app->make(TaskStorage::class);
        $second = $this->app->make(TaskStorage::class);

        $this->assertSame($first, $second);
    }

    public function test_task_validator_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskValidator::class));

        $first = $this->app->make(TaskValidator::class);
        $second = $this->app->make(TaskValidator::class);

        $this->assertSame($first, $second);
    }

    public function test_logger_interface_is_registered(): void
    {
        $this->assertTrue($this->app->bound(LoggerInterface::class));

        $first = $this->app->make(LoggerInterface::class);
        $second = $this->app->make(LoggerInterface::class);

        $this->assertSame($first, $second);
        $this->assertInstanceOf(LoggerInterface::class, $first);
    }

    public function test_task_runner_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskRunner::class));

        $first = $this->app->make(TaskRunner::class);
        $second = $this->app->make(TaskRunner::class);

        $this->assertSame($first, $second);
    }

    public function test_task_registry_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskRegistry::class));

        $first = $this->app->make(TaskRegistry::class);
        $second = $this->app->make(TaskRegistry::class);

        $this->assertSame($first, $second);
    }

    public function test_run_task_directive_is_registered(): void
    {
        $this->assertTrue($this->app->bound(RunTaskDirective::class));

        $first = $this->app->make(RunTaskDirective::class);
        $second = $this->app->make(RunTaskDirective::class);

        $this->assertSame($first, $second);
    }
}
