<?php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class TaskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/task.php', 'task');

        // Register TaskConfigInterface with ConfigRepository injection
        $this->app->singleton(TaskConfigInterface::class, function (Application $app) {
            return new TaskConfig(
                $app->make(ConfigRepository::class)
            );
        });

        // Keep TaskConfig alias for backward compatibility
        $this->app->alias(TaskConfigInterface::class, TaskConfig::class);

        // Core services - now depending on TaskConfigInterface
        $this->app->singleton(TaskStorageService::class, function (Application $app) {
            return new TaskStorageService($app->make(TaskConfigInterface::class));
        });

        $this->app->singleton(TaskValidatorService::class, function (Application $app) {
            return new TaskValidatorService($app->make(TaskConfigInterface::class));
        });

        $this->app->singleton(TaskRunnerService::class, function (Application $app) {
            return new TaskRunnerService(
                storage: $app->make(TaskStorageService::class),
                logger: $app->make(LoggerInterface::class),
                validator: $app->make(TaskValidatorService::class),
                config: $app->make(TaskConfigInterface::class),
            );
        });

        $this->app->singleton(TaskRegistryService::class, function (Application $app) {
            return new TaskRegistryService(
                storage: $app->make(TaskStorageService::class),
                validator: $app->make(TaskValidatorService::class),
            );
        });

        // BatchResultService - immutable service for building batch results
        $this->app->singleton(BatchResultService::class, function () {
            return new BatchResultService();
        });

        // TaskBatchService
        $this->app->singleton(TaskBatchService::class, function (Application $app) {
            return new TaskBatchService(
                storage: $app->make(TaskStorageService::class),
                runner: $app->make(TaskRunnerService::class),
                validator: $app->make(TaskValidatorService::class),
                logger: $app->make(LoggerInterface::class),
                batchResultService: $app->make(BatchResultService::class),
                config: $app->make(TaskConfigInterface::class),
            );
        });

        // ProcessTasksDirective
        $this->app->singleton(ProcessTasksDirective::class, function (Application $app): ProcessTasksDirective {
            return new ProcessTasksDirective(
                context: $app->make(DirectiveContext::class),
                interaction: $app->make(DirectiveInteractionService::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/task.php' => config_path('task.php'),
        ], 'task-config');
    }
}
