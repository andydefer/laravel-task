<?php

// tests/Integration/TaskServiceProviderTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration;

use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\BatchResultServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskBatchServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskFinderServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskRegistryServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskRunnerServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskValidatorServiceInterface;
use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\Services\TaskFinderService;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskService;
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

    // ==================== Service Tests (Interfaces) ====================

    public function test_task_validator_interface_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskValidatorServiceInterface::class));

        $first = $this->app->make(TaskValidatorServiceInterface::class);
        $second = $this->app->make(TaskValidatorServiceInterface::class);

        $this->assertSame($first, $second);
        $this->assertInstanceOf(TaskValidatorService::class, $first);
    }

    public function test_task_runner_interface_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskRunnerServiceInterface::class));

        $first = $this->app->make(TaskRunnerServiceInterface::class);
        $second = $this->app->make(TaskRunnerServiceInterface::class);

        $this->assertSame($first, $second);
        $this->assertInstanceOf(TaskRunnerService::class, $first);
    }

    public function test_task_registry_interface_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskRegistryServiceInterface::class));

        $first = $this->app->make(TaskRegistryServiceInterface::class);
        $second = $this->app->make(TaskRegistryServiceInterface::class);

        $this->assertSame($first, $second);
        $this->assertInstanceOf(TaskRegistryService::class, $first);
    }

    public function test_task_batch_interface_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskBatchServiceInterface::class));

        $first = $this->app->make(TaskBatchServiceInterface::class);
        $second = $this->app->make(TaskBatchServiceInterface::class);

        $this->assertSame($first, $second);
        $this->assertInstanceOf(TaskBatchService::class, $first);
    }

    public function test_batch_result_interface_is_registered(): void
    {
        $this->assertTrue($this->app->bound(BatchResultServiceInterface::class));

        $first = $this->app->make(BatchResultServiceInterface::class);
        $second = $this->app->make(BatchResultServiceInterface::class);

        $this->assertSame($first, $second);
        $this->assertInstanceOf(BatchResultService::class, $first);
    }

    public function test_task_finder_interface_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskFinderServiceInterface::class));

        $first = $this->app->make(TaskFinderServiceInterface::class);
        $second = $this->app->make(TaskFinderServiceInterface::class);

        $this->assertSame($first, $second);
        $this->assertInstanceOf(TaskFinderService::class, $first);
    }

    public function test_task_service_interface_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskServiceInterface::class));

        $first = $this->app->make(TaskServiceInterface::class);
        $second = $this->app->make(TaskServiceInterface::class);

        $this->assertSame($first, $second);
        $this->assertInstanceOf(TaskService::class, $first);
    }

    // ==================== Service Tests (Concrete Classes) ====================

    public function test_task_validator_concrete_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskValidatorService::class));

        $first = $this->app->make(TaskValidatorService::class);
        $second = $this->app->make(TaskValidatorService::class);

        $this->assertSame($first, $second);
    }

    public function test_task_runner_concrete_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskRunnerService::class));

        $first = $this->app->make(TaskRunnerService::class);
        $second = $this->app->make(TaskRunnerService::class);

        $this->assertSame($first, $second);
    }

    public function test_task_registry_concrete_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskRegistryService::class));

        $first = $this->app->make(TaskRegistryService::class);
        $second = $this->app->make(TaskRegistryService::class);

        $this->assertSame($first, $second);
    }

    public function test_task_batch_concrete_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskBatchService::class));

        $first = $this->app->make(TaskBatchService::class);
        $second = $this->app->make(TaskBatchService::class);

        $this->assertSame($first, $second);
    }

    public function test_batch_result_concrete_is_registered(): void
    {
        $this->assertTrue($this->app->bound(BatchResultService::class));

        $first = $this->app->make(BatchResultService::class);
        $second = $this->app->make(BatchResultService::class);

        $this->assertSame($first, $second);
    }

    public function test_task_finder_concrete_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskFinderService::class));

        $first = $this->app->make(TaskFinderService::class);
        $second = $this->app->make(TaskFinderService::class);

        $this->assertSame($first, $second);
    }

    public function test_task_service_concrete_is_registered(): void
    {
        $this->assertTrue($this->app->bound(TaskService::class));

        $first = $this->app->make(TaskService::class);
        $second = $this->app->make(TaskService::class);

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
        $runner = $this->app->make(TaskRunnerServiceInterface::class);

        $this->assertInstanceOf(TaskRunnerServiceInterface::class, $runner);
    }

    public function test_task_registry_receives_correct_dependencies(): void
    {
        $registry = $this->app->make(TaskRegistryServiceInterface::class);

        $this->assertInstanceOf(TaskRegistryServiceInterface::class, $registry);
    }

    public function test_task_batch_receives_correct_dependencies(): void
    {
        $batch = $this->app->make(TaskBatchServiceInterface::class);

        $this->assertInstanceOf(TaskBatchServiceInterface::class, $batch);
    }

    public function test_task_finder_receives_correct_dependencies(): void
    {
        $finder = $this->app->make(TaskFinderServiceInterface::class);

        $this->assertInstanceOf(TaskFinderServiceInterface::class, $finder);
        $this->assertInstanceOf(TaskFinderService::class, $finder);
    }

    public function test_task_service_receives_correct_dependencies(): void
    {
        $service = $this->app->make(TaskServiceInterface::class);

        $this->assertInstanceOf(TaskServiceInterface::class, $service);
        $this->assertInstanceOf(TaskService::class, $service);
    }

    // ==================== Singleton Tests ====================

    public function test_task_service_is_singleton(): void
    {
        $first = $this->app->make(TaskServiceInterface::class);
        $second = $this->app->make(TaskServiceInterface::class);

        $this->assertSame($first, $second);
    }

    public function test_task_finder_is_singleton(): void
    {
        $first = $this->app->make(TaskFinderServiceInterface::class);
        $second = $this->app->make(TaskFinderServiceInterface::class);

        $this->assertSame($first, $second);
    }

    public function test_all_services_are_singletons(): void
    {
        $services = [
            TaskValidatorServiceInterface::class,
            TaskRunnerServiceInterface::class,
            TaskRegistryServiceInterface::class,
            TaskBatchServiceInterface::class,
            BatchResultServiceInterface::class,
            TaskFinderServiceInterface::class,
            TaskServiceInterface::class,
        ];

        foreach ($services as $service) {
            $first = $this->app->make($service);
            $second = $this->app->make($service);
            $this->assertSame($first, $second, "Service {$service} is not a singleton");
        }
    }
}
