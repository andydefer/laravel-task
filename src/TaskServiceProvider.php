<?php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contexts\TaskStorageContext;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\BatchResultServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskBatchServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskFinderServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskRegistryServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskRunnerServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskValidatorServiceInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Directives\TaskUnregisterDirective;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskRepository;
use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\Services\TaskFinderService;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Strategies\TaskPathStrategy;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\UuidFactoryInterface;

final class TaskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/task.php', 'task');

        // Register TaskConfigInterface with ConfigRepository injection
        $this->app->singleton(TaskConfigInterface::class, function (Application $app) {
            return new TaskConfig(
                $app->make(ConfigRepository::class)
            );
        });

        // Keep TaskConfig alias for backward compatibility
        $this->app->alias(TaskConfigInterface::class, TaskConfig::class);

        // Register FileSystemInterface
        $this->app->singleton(FileSystemInterface::class, function () {
            return new FileSystemService;
        });

        // Register HydrationService
        $this->app->singleton(HydrationService::class);

        // Register JsonlContext
        $this->app->singleton(JsonlContext::class);

        // Register UuidFactoryInterface
        $this->app->singleton(UuidFactoryInterface::class, function () {
            return new UuidFactory;
        });

        // Register TaskPathStrategy with base path from config
        $this->app->singleton(TaskPathStrategy::class, function (Application $app) {
            $config = $app->make(TaskConfigInterface::class);

            return new TaskPathStrategy($config->storagePath());
        });

        // Register JsonlService with TaskPathStrategy
        $this->app->singleton('task.jsonl', function (Application $app) {
            return new JsonlService(
                pathStrategy: $app->make(TaskPathStrategy::class),
                fileSystem: $app->make(FileSystemInterface::class),
                context: $app->make(JsonlContext::class),
            );
        });

        // Register TaskStorageContext
        $this->app->singleton(TaskStorageContext::class, function (Application $app) {
            return new TaskStorageContext(
                $app->make(TaskConfigInterface::class)
            );
        });

        // ==================== Repositories ====================

        $this->app->singleton(TaskRepositoryInterface::class, function (Application $app) {
            return new TaskRepository(
                context: $app->make(TaskStorageContext::class),
                jsonl: $app->make('task.jsonl'),
                hydration: $app->make(HydrationService::class),
                fs: $app->make(FileSystemInterface::class),
            );
        });

        $this->app->singleton(RecurringTaskRepositoryInterface::class, function (Application $app) {
            return new RecurringTaskRepository(
                context: $app->make(TaskStorageContext::class),
                jsonl: $app->make('task.jsonl'),
                hydration: $app->make(HydrationService::class),
                fs: $app->make(FileSystemInterface::class),
            );
        });

        // ==================== Services (Interfaces) ====================

        $this->app->singleton(TaskValidatorServiceInterface::class, function (Application $app) {
            return new TaskValidatorService(
                config: $app->make(TaskConfigInterface::class),
                hydration: $app->make(HydrationService::class),
                logger: $app->make(LoggerInterface::class),
                app: $app,
            );
        });

        $this->app->singleton(TaskRunnerServiceInterface::class, function (Application $app) {
            return new TaskRunnerService(
                taskRepository: $app->make(TaskRepositoryInterface::class),
                recurringTaskRepository: $app->make(RecurringTaskRepositoryInterface::class),
                logger: $app->make(LoggerInterface::class),
                validator: $app->make(TaskValidatorServiceInterface::class),
                config: $app->make(TaskConfigInterface::class),
                hydration: $app->make(HydrationService::class),
                fs: $app->make(FileSystemInterface::class),
                app: $app,
            );
        });

        $this->app->singleton(TaskRegistryServiceInterface::class, function (Application $app) {
            return new TaskRegistryService(
                taskRepository: $app->make(TaskRepositoryInterface::class),
                recurringTaskRepository: $app->make(RecurringTaskRepositoryInterface::class),
                validator: $app->make(TaskValidatorServiceInterface::class),
                hydration: $app->make(HydrationService::class),
                uuidFactory: $app->make(UuidFactoryInterface::class),
                laravelApp: $app,
            );
        });

        $this->app->singleton(BatchResultServiceInterface::class, function (Application $app) {
            return new BatchResultService(
                hydration: $app->make(HydrationService::class),
            );
        });

        $this->app->singleton(TaskBatchServiceInterface::class, function (Application $app) {
            return new TaskBatchService(
                taskRepository: $app->make(TaskRepositoryInterface::class),
                recurringTaskRepository: $app->make(RecurringTaskRepositoryInterface::class),
                runner: $app->make(TaskRunnerServiceInterface::class),
                validator: $app->make(TaskValidatorServiceInterface::class),
                logger: $app->make(LoggerInterface::class),
                batchResultService: $app->make(BatchResultServiceInterface::class),
                config: $app->make(TaskConfigInterface::class),
                hydration: $app->make(HydrationService::class),
            );
        });

        $this->app->singleton(TaskFinderServiceInterface::class, function (Application $app) {
            return new TaskFinderService(
                taskRepository: $app->make(TaskRepositoryInterface::class),
                recurringTaskRepository: $app->make(RecurringTaskRepositoryInterface::class),
            );
        });

        $this->app->singleton(TaskServiceInterface::class, function (Application $app) {
            return new TaskService(
                registry: $app->make(TaskRegistryServiceInterface::class),
                runner: $app->make(TaskRunnerServiceInterface::class),
                validator: $app->make(TaskValidatorServiceInterface::class),
                batch: $app->make(TaskBatchServiceInterface::class),
                batchResult: $app->make(BatchResultServiceInterface::class),
                finder: $app->make(TaskFinderServiceInterface::class),
            );
        });

        // ==================== Services (Concrete Classes - Aliases for convenience) ====================

        // Bind concrete classes to the same instances as their interfaces
        $this->app->singleton(TaskValidatorService::class, function (Application $app) {
            return $app->make(TaskValidatorServiceInterface::class);
        });

        $this->app->singleton(TaskRunnerService::class, function (Application $app) {
            return $app->make(TaskRunnerServiceInterface::class);
        });

        $this->app->singleton(TaskRegistryService::class, function (Application $app) {
            return $app->make(TaskRegistryServiceInterface::class);
        });

        $this->app->singleton(TaskBatchService::class, function (Application $app) {
            return $app->make(TaskBatchServiceInterface::class);
        });

        $this->app->singleton(BatchResultService::class, function (Application $app) {
            return $app->make(BatchResultServiceInterface::class);
        });

        $this->app->singleton(TaskFinderService::class, function (Application $app) {
            return $app->make(TaskFinderServiceInterface::class);
        });

        $this->app->singleton(TaskService::class, function (Application $app) {
            return $app->make(TaskServiceInterface::class);
        });

        // ==================== Directives ====================

        $this->app->singleton(ProcessTasksDirective::class, function (Application $app): ProcessTasksDirective {
            return new ProcessTasksDirective(
                context: $app->make(DirectiveContext::class),
                interaction: $app->make(DirectiveInteractionService::class),
            );
        });

        $this->app->singleton(TaskUnregisterDirective::class, function (Application $app): TaskUnregisterDirective {
            return new TaskUnregisterDirective(
                context: $app->make(DirectiveContext::class),
                interaction: $app->make(DirectiveInteractionService::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/task.php' => config_path('task.php'),
        ], 'task-config');
    }
}
