<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\LoggerService;
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
use AndyDefer\Task\Contracts\Validators\RecurringTaskValidatorInterface;
use AndyDefer\Task\Contracts\Validators\UniqueTaskValidatorInterface;
use AndyDefer\Task\Directives\TasksProcessDirective;
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
use AndyDefer\Task\TaskServiceProvider;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\Validators\UniqueTaskValidator;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\UuidFactoryInterface;

final class TaskServiceProviderTest extends IntegrationTestCase
{
    public function test_hydration_service_is_bound(): void
    {
        $this->assertTrue($this->app->bound(HydrationService::class));

        $instance = $this->app->make(HydrationService::class);
        $this->assertInstanceOf(HydrationService::class, $instance);
    }

    public function test_logger_is_bound(): void
    {
        $this->assertTrue($this->app->bound(LoggerInterface::class));

        $instance = $this->app->make(LoggerInterface::class);
        $this->assertInstanceOf(LoggerService::class, $instance);
    }

    public function test_uuid_factory_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UuidFactoryInterface::class));

        $instance = $this->app->make(UuidFactoryInterface::class);
        $this->assertInstanceOf(UuidFactory::class, $instance);
    }

    // ==================== CONSOLE WRITER ====================

    public function test_console_writer_is_bound(): void
    {
        $this->assertTrue($this->app->bound(Console::class));

        $instance = $this->app->make(Console::class);
        $this->assertInstanceOf(Console::class, $instance);
    }

    public function test_console_writer_alias_works(): void
    {
        $this->assertTrue($this->app->bound('console.writer'));

        $instance1 = $this->app->make(Console::class);
        $instance2 = $this->app->make('console.writer');

        $this->assertSame($instance1, $instance2);
    }

    // ==================== REPOSITORIES ====================

    public function test_task_execution_debug_repository_is_bound(): void
    {
        $this->assertTrue($this->app->bound(TaskExecutionDebugRepositoryInterface::class));

        $instance = $this->app->make(TaskExecutionDebugRepositoryInterface::class);
        $this->assertInstanceOf(TaskExecutionDebugRepository::class, $instance);
    }

    public function test_task_execution_debug_repository_alias_works(): void
    {
        $this->assertTrue($this->app->bound(TaskExecutionDebugRepository::class));

        $instance1 = $this->app->make(TaskExecutionDebugRepositoryInterface::class);
        $instance2 = $this->app->make(TaskExecutionDebugRepository::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_recurring_task_repository_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskRepositoryInterface::class));

        $instance = $this->app->make(RecurringTaskRepositoryInterface::class);
        $this->assertInstanceOf(RecurringTaskRepository::class, $instance);
    }

    public function test_recurring_task_repository_alias_works(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskRepository::class));

        $instance1 = $this->app->make(RecurringTaskRepositoryInterface::class);
        $instance2 = $this->app->make(RecurringTaskRepository::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_unique_task_repository_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskRepositoryInterface::class));

        $instance = $this->app->make(UniqueTaskRepositoryInterface::class);
        $this->assertInstanceOf(UniqueTaskRepository::class, $instance);
    }

    public function test_unique_task_repository_alias_works(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskRepository::class));

        $instance1 = $this->app->make(UniqueTaskRepositoryInterface::class);
        $instance2 = $this->app->make(UniqueTaskRepository::class);

        $this->assertSame($instance1, $instance2);
    }

    // ==================== VALIDATORS ====================

    public function test_unique_task_validator_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskValidatorInterface::class));

        $instance = $this->app->make(UniqueTaskValidatorInterface::class);
        $this->assertInstanceOf(UniqueTaskValidator::class, $instance);
    }

    public function test_unique_task_validator_alias_works(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskValidator::class));

        $instance1 = $this->app->make(UniqueTaskValidatorInterface::class);
        $instance2 = $this->app->make(UniqueTaskValidator::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_recurring_task_validator_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskValidatorInterface::class));

        $instance = $this->app->make(RecurringTaskValidatorInterface::class);
        $this->assertInstanceOf(RecurringTaskValidator::class, $instance);
    }

    public function test_recurring_task_validator_alias_works(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskValidator::class));

        $instance1 = $this->app->make(RecurringTaskValidatorInterface::class);
        $instance2 = $this->app->make(RecurringTaskValidator::class);

        $this->assertSame($instance1, $instance2);
    }

    // ==================== LOGGERS ====================

    public function test_unique_task_logger_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskLoggerInterface::class));

        $instance = $this->app->make(UniqueTaskLoggerInterface::class);
        $this->assertInstanceOf(UniqueTaskLogger::class, $instance);
    }

    public function test_unique_task_logger_alias_works(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskLogger::class));

        $instance1 = $this->app->make(UniqueTaskLoggerInterface::class);
        $instance2 = $this->app->make(UniqueTaskLogger::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_recurring_task_logger_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskLoggerInterface::class));

        $instance = $this->app->make(RecurringTaskLoggerInterface::class);
        $this->assertInstanceOf(RecurringTaskLogger::class, $instance);
    }

    public function test_recurring_task_logger_alias_works(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskLogger::class));

        $instance1 = $this->app->make(RecurringTaskLoggerInterface::class);
        $instance2 = $this->app->make(RecurringTaskLogger::class);

        $this->assertSame($instance1, $instance2);
    }

    // ==================== RUNNERS ====================

    public function test_unique_task_runner_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskRunnerInterface::class));

        $instance = $this->app->make(UniqueTaskRunnerInterface::class);
        $this->assertInstanceOf(UniqueTaskRunner::class, $instance);
    }

    public function test_unique_task_runner_alias_works(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskRunner::class));

        $instance1 = $this->app->make(UniqueTaskRunnerInterface::class);
        $instance2 = $this->app->make(UniqueTaskRunner::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_recurring_task_runner_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskRunnerInterface::class));

        $instance = $this->app->make(RecurringTaskRunnerInterface::class);
        $this->assertInstanceOf(RecurringTaskRunner::class, $instance);
    }

    public function test_recurring_task_runner_alias_works(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskRunner::class));

        $instance1 = $this->app->make(RecurringTaskRunnerInterface::class);
        $instance2 = $this->app->make(RecurringTaskRunner::class);

        $this->assertSame($instance1, $instance2);
    }

    // ==================== PROCESSORS ====================

    public function test_unique_task_processor_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskProcessorInterface::class));

        $instance = $this->app->make(UniqueTaskProcessorInterface::class);
        $this->assertInstanceOf(UniqueTaskProcessor::class, $instance);
    }

    public function test_unique_task_processor_alias_works(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskProcessor::class));

        $instance1 = $this->app->make(UniqueTaskProcessorInterface::class);
        $instance2 = $this->app->make(UniqueTaskProcessor::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_recurring_task_processor_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskProcessorInterface::class));

        $instance = $this->app->make(RecurringTaskProcessorInterface::class);
        $this->assertInstanceOf(RecurringTaskProcessor::class, $instance);
    }

    public function test_recurring_task_processor_alias_works(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskProcessor::class));

        $instance1 = $this->app->make(RecurringTaskProcessorInterface::class);
        $instance2 = $this->app->make(RecurringTaskProcessor::class);

        $this->assertSame($instance1, $instance2);
    }

    // ==================== SERVICES ====================

    public function test_task_execution_debug_service_is_bound(): void
    {
        $this->assertTrue($this->app->bound(TaskExecutionDebugServiceInterface::class));

        $instance = $this->app->make(TaskExecutionDebugServiceInterface::class);
        $this->assertInstanceOf(TaskExecutionDebugService::class, $instance);
    }

    public function test_task_execution_debug_service_alias_works(): void
    {
        $this->assertTrue($this->app->bound(TaskExecutionDebugService::class));

        $instance1 = $this->app->make(TaskExecutionDebugServiceInterface::class);
        $instance2 = $this->app->make(TaskExecutionDebugService::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_unique_task_service_is_bound(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskServiceInterface::class));

        $instance = $this->app->make(UniqueTaskServiceInterface::class);
        $this->assertInstanceOf(UniqueTaskService::class, $instance);
    }

    public function test_unique_task_service_alias_works(): void
    {
        $this->assertTrue($this->app->bound(UniqueTaskService::class));

        $instance1 = $this->app->make(UniqueTaskServiceInterface::class);
        $instance2 = $this->app->make(UniqueTaskService::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_recurring_task_service_is_bound(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskServiceInterface::class));

        $instance = $this->app->make(RecurringTaskServiceInterface::class);
        $this->assertInstanceOf(RecurringTaskService::class, $instance);
    }

    public function test_recurring_task_service_alias_works(): void
    {
        $this->assertTrue($this->app->bound(RecurringTaskService::class));

        $instance1 = $this->app->make(RecurringTaskServiceInterface::class);
        $instance2 = $this->app->make(RecurringTaskService::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_directive_kernel_is_bound(): void
    {
        $this->assertTrue($this->app->bound(DirectiveKernel::class));

        $instance = $this->app->make(DirectiveKernel::class);
        $this->assertInstanceOf(DirectiveKernel::class, $instance);
    }

    // ==================== SINGLETONS ====================

    public function test_logger_is_singleton(): void
    {
        $this->assertTrue($this->app->bound(LoggerInterface::class));

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

    public function test_console_writer_is_singleton(): void
    {
        $instance1 = $this->app->make(Console::class);
        $instance2 = $this->app->make(Console::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_directive_kernel_is_singleton(): void
    {
        $instance1 = $this->app->make(DirectiveKernel::class);
        $instance2 = $this->app->make(DirectiveKernel::class);

        $this->assertSame($instance1, $instance2);
    }

    // ==================== DIRECTIVES ====================

    public function test_process_tasks_directive_exists(): void
    {
        $this->assertTrue(class_exists(TasksProcessDirective::class));
    }

    public function test_tasks_watch_directive_exists(): void
    {
        $this->assertTrue(class_exists(TasksWatchDirective::class));
    }

    // ==================== SERVICE PROVIDER ====================

    public function test_service_provider_registers_services(): void
    {
        $provider = new TaskServiceProvider($this->app);
        $provider->register();

        $this->assertTrue($this->app->bound(LoggerInterface::class));
        $this->assertTrue($this->app->bound(UniqueTaskServiceInterface::class));
        $this->assertTrue($this->app->bound(RecurringTaskServiceInterface::class));
        $this->assertTrue($this->app->bound(Console::class));
        $this->assertTrue($this->app->bound(DirectiveKernel::class));
    }

    public function test_service_provider_boots_migrations(): void
    {
        $provider = new TaskServiceProvider($this->app);
        $provider->boot();

        $this->assertTrue(method_exists($provider, 'loadMigrationsFrom'));
    }
}
