<?php

// src/TaskServiceProvider.php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Directive\Services\LaravelBootstrapper;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Directives\MakeTaskDirective;
use AndyDefer\Task\Directives\RunTaskDirective;
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

        $this->app->singleton(TaskStorage::class, function (Application $app) {
            $storagePath = $app['config']->get('task.storage_path', storage_path('tasks'));
            return new TaskStorage($storagePath);
        });

        $this->app->singleton(TaskValidator::class, function () {
            return new TaskValidator();
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

        $this->app->singleton(RunTaskDirective::class, function (Application $app) {
            return new RunTaskDirective(
                interaction: $app->make(DirectiveInteractionService::class),
                storage: $app->make(TaskStorage::class),
                runner: $app->make(TaskRunner::class),
                validator: $app->make(TaskValidator::class),
                logger: $app->make(Logger::class),
                laravelBootstrapper: $app->make(LaravelBootstrapper::class),
            );
        });

        $this->app->singleton(MakeTaskDirective::class, function (Application $app) {
            return new MakeTaskDirective(
                interaction: $app->make(DirectiveInteractionService::class),
                stubPath: __DIR__ . '/../stubs/task.stub',  // ← Ajout du chemin du stub
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
