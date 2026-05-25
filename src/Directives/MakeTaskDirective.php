<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Records\Collections\Utility\StringTypedCollection;

final class MakeTaskDirective extends AbstractDirective
{
    private const TASKS_PATH = '/app/Tasks/';
    private string $stubPath;

    public function __construct(
        DirectiveInteractionService $interaction,
        ?string $stubPath = null,
    ) {
        parent::__construct($interaction);
        $this->stubPath = $stubPath ?? __DIR__ . '/../../stubs/task.stub';
    }

    public function getSignature(): string
    {
        return 'make-task {name}';
    }

    public function getDescription(): string
    {
        return 'Create a new Task class';
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

        if ($name === null) {
            $this->error('Task name is required');
            $this->line('Usage: directive make-task <name>');
            $this->line('Example: directive make-task send-welcome-email');
            return ExitCode::INVALID_ARGUMENT;
        }

        $className = $this->generateClassName($name);

        if (!$this->createTaskFile($className, $name)) {
            return ExitCode::FAILURE;
        }

        $this->info('✅ Task created successfully!');
        $this->line("   Class: {$className}");
        $this->line("   Path: " . getcwd() . self::TASKS_PATH . $className . '.php');
        $this->line("   Signature: {$name}");

        return ExitCode::SUCCESS;
    }

    private function generateClassName(string $signature): string
    {
        $parts = explode('-', $signature);
        $parts = array_map('ucfirst', $parts);
        $className = implode('', $parts);

        if (!str_ends_with($className, 'Task')) {
            $className .= 'Task';
        }

        return $className;
    }

    private function createTaskFile(string $className, string $signature): bool
    {
        $directory = getcwd() . self::TASKS_PATH;
        $filePath = $directory . $className . '.php';

        if (file_exists($filePath)) {
            $this->error("Task already exists: {$filePath}");
            return false;
        }

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                $this->error("Cannot create directory: {$directory}");
                return false;
            }
            $this->line("📁 Created directory: app/Tasks/");
        }

        $stub = file_get_contents($this->stubPath);
        if ($stub === false) {
            $this->error("Stub template not found at: {$this->stubPath}");
            return false;
        }

        $content = str_replace(
            ['{{ class }}', '{{ signature }}'],
            [$className, $signature],
            $stub
        );

        if (file_put_contents($filePath, $content) === false) {
            $this->error("Cannot create file: {$filePath}");
            return false;
        }

        return true;
    }
}
