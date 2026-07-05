<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration;

use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Loggers\RecurringTaskLoggerInterface;
use AndyDefer\Task\Contracts\Loggers\UniqueTaskLoggerInterface;
use AndyDefer\Task\Contracts\Processors\RecurringTaskProcessorInterface;
use AndyDefer\Task\Contracts\Processors\UniqueTaskProcessorInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskExecutionDebugRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Runners\RecurringTaskRunnerInterface;
use AndyDefer\Task\Contracts\Runners\UniqueTaskRunnerInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskExecutionDebugServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\WatchRendererServiceInterface;
use AndyDefer\Task\Contracts\Services\WatchServiceInterface;
use AndyDefer\Task\Contracts\Validators\RecurringTaskValidatorInterface;
use AndyDefer\Task\Contracts\Validators\UniqueTaskValidatorInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Directives\TasksWatchDirective;
use AndyDefer\Task\Loggers\RecurringTaskLogger;
use AndyDefer\Task\Loggers\UniqueTaskLogger;
use AndyDefer\Task\Processors\RecurringTaskProcessor;
use AndyDefer\Task\Processors\UniqueTaskProcessor;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Runners\RecurringTaskRunner;
use AndyDefer\Task\Runners\UniqueTaskRunner;
use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\Services\TaskExecutionDebugService;
use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\Services\WatchRendererService;
use AndyDefer\Task\Services\WatchService;
use AndyDefer\Task\TaskServiceProvider;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\Validators\UniqueTaskValidator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Ramsey\Uuid\UuidFactoryInterface;

final class TaskServiceProviderTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(TaskServiceProvider::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ==================== SERVICES DE BASE ====================

    public function test_logger_interface_is_bound(): void
    {
        $this->assertTrue($this->app->bound(LoggerInterface::class));

        $instance = $this->app->make(LoggerInterface::class);
        $this->assertInstanceOf(LoggerInterface::class, $instance);
    }

    public function test_uuid_factory_interface_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UuidFactoryInterface::class));

        $instance = $this->app->make(UuidFactoryInterface::class);
        $this->assertInstanceOf(UuidFactoryInterface::class, $instance);
    }

    // ==================== REPOSITORIES ====================

    public function test_task_execution_debug_repository_is_bound(): void
    {
        $this->assertTrue($this->app->bound(TaskExecutionDebugRepositoryInterface::class));

        $instance = $this->app->make(TaskExecutionDebugRepositoryInterface::class);
        $this->assertInstanceOf(TaskExecutionDebugRepository::class, $instance);
    }

    public function test_unique_task_repository_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskRepositoryInterface::class));

        $instance = $this->app->make(UniqueTaskRepositoryInterface::class);
        $this->assertInstanceOf(UniqueTaskRepository::class, $instance);
    }

    public function test_recurring_task_repository_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskRepositoryInterface::class));

        $instance = $this->app->make(RecurringTaskRepositoryInterface::class);
        $this->assertInstanceOf(RecurringTaskRepository::class, $instance);
    }

    // ==================== VALIDATORS ====================

    public function test_unique_task_validator_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskValidatorInterface::class));

        $instance = $this->app->make(UniqueTaskValidatorInterface::class);
        $this->assertInstanceOf(UniqueTaskValidator::class, $instance);
    }

    public function test_recurring_task_validator_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskValidatorInterface::class));

        $instance = $this->app->make(RecurringTaskValidatorInterface::class);
        $this->assertInstanceOf(RecurringTaskValidator::class, $instance);
    }

    // ==================== LOGGERS ====================

    public function test_unique_task_logger_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskLoggerInterface::class));

        $instance = $this->app->make(UniqueTaskLoggerInterface::class);
        $this->assertInstanceOf(UniqueTaskLogger::class, $instance);
    }

    public function test_recurring_task_logger_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskLoggerInterface::class));

        $instance = $this->app->make(RecurringTaskLoggerInterface::class);
        $this->assertInstanceOf(RecurringTaskLogger::class, $instance);
    }

    // ==================== RUNNERS ====================

    public function test_unique_task_runner_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskRunnerInterface::class));

        $instance = $this->app->make(UniqueTaskRunnerInterface::class);
        $this->assertInstanceOf(UniqueTaskRunner::class, $instance);
    }

    public function test_recurring_task_runner_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskRunnerInterface::class));

        $instance = $this->app->make(RecurringTaskRunnerInterface::class);
        $this->assertInstanceOf(RecurringTaskRunner::class, $instance);
    }

    // ==================== PROCESSORS ====================

    public function test_unique_task_processor_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskProcessorInterface::class));

        $instance = $this->app->make(UniqueTaskProcessorInterface::class);
        $this->assertInstanceOf(UniqueTaskProcessor::class, $instance);
    }

    public function test_recurring_task_processor_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskProcessorInterface::class));

        $instance = $this->app->make(RecurringTaskProcessorInterface::class);
        $this->assertInstanceOf(RecurringTaskProcessor::class, $instance);
    }

    // ==================== SERVICES ====================

    public function test_task_execution_debug_service_is_bound(): void
    {
        $this->assertTrue($this->app->bound(TaskExecutionDebugServiceInterface::class));

        $instance = $this->app->make(TaskExecutionDebugServiceInterface::class);
        $this->assertInstanceOf(TaskExecutionDebugService::class, $instance);
    }

    public function test_unique_task_service_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskServiceInterface::class));

        $instance = $this->app->make(UniqueTaskServiceInterface::class);
        $this->assertInstanceOf(UniqueTaskService::class, $instance);
    }

    public function test_recurring_task_service_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskServiceInterface::class));

        $instance = $this->app->make(RecurringTaskServiceInterface::class);
        $this->assertInstanceOf(RecurringTaskService::class, $instance);
    }

    // ==================== WATCH SERVICES ====================

    public function test_watch_service_is_bound(): void
    {
        $this->assertTrue($this->app->bound(WatchServiceInterface::class));

        $instance = $this->app->make(WatchServiceInterface::class);
        $this->assertInstanceOf(WatchService::class, $instance);
    }

    public function test_watch_renderer_service_is_bound(): void
    {
        $this->assertTrue($this->app->bound(WatchRendererServiceInterface::class));

        $instance = $this->app->make(WatchRendererServiceInterface::class);
        $this->assertInstanceOf(WatchRendererService::class, $instance);
    }

    // ==================== DIRECTIVES ====================

    public function test_process_tasks_directive_is_bound(): void
    {
        $this->assertTrue($this->app->bound(ProcessTasksDirective::class));

        $instance = $this->app->make(ProcessTasksDirective::class);
        $this->assertInstanceOf(ProcessTasksDirective::class, $instance);
    }

    public function test_tasks_watch_directive_is_bound(): void
    {
        $this->assertTrue($this->app->bound(TasksWatchDirective::class));

        $instance = $this->app->make(TasksWatchDirective::class);
        $this->assertInstanceOf(TasksWatchDirective::class, $instance);
    }

    // ==================== ALIASES ====================

    public function test_unique_task_service_alias_works(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskService::class));

        $instance1 = $this->app->make(UniqueTaskServiceInterface::class);
        $instance2 = $this->app->make(UniqueTaskService::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_recurring_task_service_alias_works(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskService::class));

        $instance1 = $this->app->make(RecurringTaskServiceInterface::class);
        $instance2 = $this->app->make(RecurringTaskService::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_unique_task_repository_alias_works(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskRepository::class));

        $instance1 = $this->app->make(UniqueTaskRepositoryInterface::class);
        $instance2 = $this->app->make(UniqueTaskRepository::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_recurring_task_repository_alias_works(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskRepository::class));

        $instance1 = $this->app->make(RecurringTaskRepositoryInterface::class);
        $instance2 = $this->app->make(RecurringTaskRepository::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_task_execution_debug_service_alias_works(): void
    {
        $this->assertTrue($this->app->bound(TaskExecutionDebugService::class));

        $instance1 = $this->app->make(TaskExecutionDebugServiceInterface::class);
        $instance2 = $this->app->make(TaskExecutionDebugService::class);

        $this->assertSame($instance1, $instance2);
    }

    // ==================== SINGLETONS ====================

    public function test_logger_is_singleton(): void
    {
        $instance1 = $this->app->make(LoggerInterface::class);
        $instance2 = $this->app->make(LoggerInterface::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_unique_task_service_is_singleton(): void
    {
        $instance1 = $this->app->make(UniqueTaskServiceInterface::class);
        $instance2 = $this->app->make(UniqueTaskServiceInterface::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_recurring_task_service_is_singleton(): void
    {
        $instance1 = $this->app->make(RecurringTaskServiceInterface::class);
        $instance2 = $this->app->make(RecurringTaskServiceInterface::class);

        $this->assertSame($instance1, $instance2);
    }

    // ==================== MIGRATIONS ====================

    public function test_migrations_are_executed(): void
    {
        $tables = $this->app['db']->connection()->getSchemaBuilder()->getTables();
        $tableNames = array_map(fn ($table) => $table['name'], $tables);

        $this->assertContains('unique_tasks', $tableNames);
        $this->assertContains('recurring_tasks', $tableNames);
        $this->assertContains('task_execution_debugs', $tableNames);
    }
}
