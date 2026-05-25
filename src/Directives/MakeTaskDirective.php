<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Directive\Services\LaravelBootstrapper;
use AndyDefer\Records\Collections\Utility\StringTypedCollection;
use Illuminate\Filesystem\Filesystem;

final class MakeTaskDirective extends AbstractDirective
{
    private Filesystem $files;

    public function __construct(
        DirectiveInteractionService $interaction,
        ?LaravelBootstrapper $laravelBootstrapper = null,
    ) {
        parent::__construct($interaction, $laravelBootstrapper);
        $this->files = new Filesystem;
    }

    public function getSignature(): string
    {
        return 'make-task {name : The name of the task (e.g., Users/SendWelcomeEmailTask)} 
                       {--force : Overwrite existing files}
                       {--signature= : Custom task signature (defaults to kebab-case name)}
                       {--description= : Task description}
                       {--delay=300 : Delay in seconds between retries}
                       {--max-attempts=3 : Maximum number of attempts}
                       {--start-at= : Start date (Y-m-d H:i:s)}
                       {--end-at= : End date (Y-m-d H:i:s)}';
    }

    public function getDescription(): string
    {
        return 'Create a new Task class extending AbstractTask';
    }

    public function getAliases(): StringTypedCollection
    {
        $aliases = new StringTypedCollection;
        $aliases->add('task-make');
        $aliases->add('create-task');

        return $aliases;
    }

    public function shouldBootLaravel(): bool
    {
        return true;
    }

    public function execute(): ExitCode
    {
        $name = $this->argument('name');
        $force = $this->hasOption('force');

        if ($name === null) {
            $this->error('Task name is required.');

            return ExitCode::FAILURE;
        }

        $this->info("Creating task: {$name}");

        if (! $this->createTask($name, $force)) {
            return ExitCode::FAILURE;
        }

        $this->info("Task '{$name}' created successfully!");

        return ExitCode::SUCCESS;
    }

    private function createTask(string $name, bool $force): bool
    {
        $path = $this->getTaskPath($name);
        $namespace = $this->getTaskNamespace($name);
        $className = $this->getClassName($name);
        $signature = $this->option('signature') ?? $this->generateSignature($className);
        $description = $this->option('description') ?? "Description for {$className}";
        $delay = (int) ($this->option('delay') ?? 300);
        $maxAttempts = (int) ($this->option('max-attempts') ?? 3);
        $startAt = $this->option('start-at') ?? 'null';
        $endAt = $this->option('end-at') ?? 'null';

        if ($startAt !== 'null') {
            $startAt = "'{$startAt}'";
        }

        if ($endAt !== 'null') {
            $endAt = "'{$endAt}'";
        }

        if ($this->files->exists($path) && ! $force) {
            $this->error("Task already exists at: {$path}");

            return false;
        }

        $stub = $this->getStub('task.stub');
        $content = str_replace(
            [
                '{{ namespace }}',
                '{{ class }}',
                '{{ signature }}',
                '{{ description }}',
                '{{ delay_seconds }}',
                '{{ max_attempts }}',
                '{{ start_at }}',
                '{{ end_at }}',
            ],
            [
                $namespace,
                $className,
                $signature,
                $description,
                $delay,
                $maxAttempts,
                $startAt,
                $endAt,
            ],
            $stub
        );

        $this->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);

        return true;
    }

    private function getTaskPath(string $name): string
    {
        $basePath = app_path('Tasks');
        $segments = explode('/', $name);
        $className = array_pop($segments);

        if (! empty($segments)) {
            $basePath .= '/' . implode('/', $segments);
        }

        return "{$basePath}/{$className}.php";
    }

    private function getTaskNamespace(string $name): string
    {
        $segments = explode('/', $name);
        array_pop($segments);

        $baseNamespace = 'App\\Tasks';

        if (! empty($segments)) {
            $baseNamespace .= '\\' . implode('\\', $segments);
        }

        return $baseNamespace;
    }

    private function getClassName(string $name): string
    {
        $segments = explode('/', $name);

        return array_pop($segments);
    }

    private function generateSignature(string $className): string
    {
        // Convert PascalCase to kebab-case
        $kebab = preg_replace('/(?<!^)([A-Z])/', '-$1', $className);
        return strtolower($kebab);
    }

    private function getStub(string $name): string
    {
        $stubPath = __DIR__ . '/../../stubs/' . $name;

        return $this->files->get($stubPath);
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (! $this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0755, true);
        }
    }
}
