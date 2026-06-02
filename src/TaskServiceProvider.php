<?php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class TaskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/task.php', 'task');

        // TaskConfig - Configuration object
        $this->app->singleton(TaskConfig::class, function () {
            return new TaskConfig();
        });

        // Core services
        $this->app->singleton(TaskStorageService::class, function (Application $app) {
            return new TaskStorageService($app->make(TaskConfig::class));
        });

        $this->app->singleton(TaskValidatorService::class, function (Application $app) {
            return new TaskValidatorService($app->make(TaskConfig::class));
        });

        $this->app->singleton(TaskRunnerService::class, function (Application $app) {
            return new TaskRunnerService(
                storage: $app->make(TaskStorageService::class),
                logger: $app->make(Logger::class),
                validator: $app->make(TaskValidatorService::class),
                config: $app->make(TaskConfig::class),
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
                logger: $app->make(Logger::class),
                batchResultService: $app->make(BatchResultService::class),
                config: $app->make(TaskConfig::class),
            );
        });

        // ProcessTasksDirective
        $this->app->singleton(ProcessTasksDirective::class, function (Application $app) {
            return new ProcessTasksDirective(
                interaction: $app->make(DirectiveInteractionService::class),
                batch: $app->make(TaskBatchService::class),
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
