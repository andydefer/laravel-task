<?php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Services\TaskBatch;
use AndyDefer\Task\Services\TaskRegistry;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class TaskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/task.php', 'task');

        // Core services
        $this->app->singleton(TaskStorage::class, function (Application $app) {
            $storagePath = $app['config']->get('task.storage_path', storage_path('tasks'));

            return new TaskStorage($storagePath);
        });

        $this->app->singleton(TaskValidator::class, function () {
            return new TaskValidator;
        });

        $this->app->singleton(TaskRunner::class, function (Application $app) {
            return new TaskRunner(
                storage: $app->make(TaskStorage::class),
                logger: $app->make(Logger::class),
                validator: $app->make(TaskValidator::class),
            );
        });

        $this->app->singleton(TaskRegistry::class, function (Application $app) {
            return new TaskRegistry(
                storage: $app->make(TaskStorage::class),
                validator: $app->make(TaskValidator::class),
            );
        });

        // NEW: TaskBatch service
        $this->app->singleton(TaskBatch::class, function (Application $app) {
            return new TaskBatch(
                storage: $app->make(TaskStorage::class),
                runner: $app->make(TaskRunner::class),
                validator: $app->make(TaskValidator::class),
                logger: $app->make(Logger::class),
            );
        });

        // NEW: ProcessTasksDirective (recommended)
        $this->app->singleton(ProcessTasksDirective::class, function (Application $app) {
            return new ProcessTasksDirective(
                interaction: $app->make(DirectiveInteractionService::class),
                batch: $app->make(TaskBatch::class),
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
