<?php

// tests/Unit/TaskServiceProviderTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration;

use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class TaskServiceProviderTest extends IntegrationTestCase
{
    public function test_task_storage_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskStorageService::class));

        $first = $this->app->make(TaskStorageService::class);
        $second = $this->app->make(TaskStorageService::class);

        $this->assertSame($first, $second);
    }

    public function test_task_validator_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskValidatorService::class));

        $first = $this->app->make(TaskValidatorService::class);
        $second = $this->app->make(TaskValidatorService::class);

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
        $this->assertTrue($this->app->bound(TaskRunnerService::class));

        $first = $this->app->make(TaskRunnerService::class);
        $second = $this->app->make(TaskRunnerService::class);

        $this->assertSame($first, $second);
    }

    public function test_task_registry_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskRegistryService::class));

        $first = $this->app->make(TaskRegistryService::class);
        $second = $this->app->make(TaskRegistryService::class);

        $this->assertSame($first, $second);
    }
}
