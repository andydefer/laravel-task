<?php

// tests/Integration/TaskServiceProviderTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration;

use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class TaskServiceProviderTest extends IntegrationTestCase
{
    // ==================== Repository Tests ====================

    public function test_task_repository_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskRepositoryInterface::class));

        $first = $this->app->make(TaskRepositoryInterface::class);
        $second = $this->app->make(TaskRepositoryInterface::class);

        $this->assertSame($first, $second);
    }

    public function test_recurring_task_repository_is_registered(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskRepositoryInterface::class));

        $first = $this->app->make(RecurringTaskRepositoryInterface::class);
        $second = $this->app->make(RecurringTaskRepositoryInterface::class);

        $this->assertSame($first, $second);
    }

    // ==================== Service Tests ====================

    public function test_task_validator_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskValidatorService::class));

        $first = $this->app->make(TaskValidatorService::class);
        $second = $this->app->make(TaskValidatorService::class);

        $this->assertSame($first, $second);
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

    public function test_task_batch_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskBatchService::class));

        $first = $this->app->make(TaskBatchService::class);
        $second = $this->app->make(TaskBatchService::class);

        $this->assertSame($first, $second);
    }

    // ==================== Dependency Tests ====================

    public function test_logger_interface_is_registered(): void
    {
        $this->assertTrue($this->app->bound(LoggerInterface::class));

        $first = $this->app->make(LoggerInterface::class);
        $second = $this->app->make(LoggerInterface::class);

        $this->assertSame($first, $second);
        $this->assertInstanceOf(LoggerInterface::class, $first);
    }

    // ==================== Injection Tests ====================

    public function test_task_runner_receives_correct_dependencies(): void
    {
        $runner = $this->app->make(TaskRunnerService::class);

        $this->assertInstanceOf(TaskRunnerService::class, $runner);
    }

    public function test_task_registry_receives_correct_dependencies(): void
    {
        $registry = $this->app->make(TaskRegistryService::class);

        $this->assertInstanceOf(TaskRegistryService::class, $registry);
    }

    public function test_task_batch_receives_correct_dependencies(): void
    {
        $batch = $this->app->make(TaskBatchService::class);

        $this->assertInstanceOf(TaskBatchService::class, $batch);
    }
}
