<?php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\Task\Bootstrap\ApplicationFactory;
use AndyDefer\Task\Container\TaskContainer;
use Throwable;

/**
 * Task Application - Main entry point for Laravel Task.
 *
 * This class provides a convenient way to run Task directives
 * from within your application or from the command line.
 *
 * @example
 * // From within your application
 * $app = TaskApp::create(__DIR__);
 * $exitCode = $app->runDirective('tasks:process', ['--verbose']);
 *
 * // From CLI
 * $app = TaskApp::create(__DIR__);
 * $exitCode = $app->run($argv);
 */
final class TaskApp
{
    private TaskContainer $container;

    private DirectiveKernel $kernel;

    private Console $console;

    private bool $verbose = false;

    private function __construct(string $basePath)
    {

        // $application = ApplicationFactory::create();

        $this->container = TaskContainer::create(ApplicationFactory::create(), $basePath);
        $this->kernel = $this->container->make(DirectiveKernel::class);
        $this->console = $this->container->make(Console::class);
    }

    /**
     * Create a new TaskApp instance.
     *
     * @param  string  $basePath  The project root path
     */
    public static function create(string $basePath): self
    {
        return new self($basePath);
    }

    /**
     * Run the task application with command-line arguments.
     *
     * @param  array<int, string>  $argv  The command-line arguments
     * @return int The exit code
     */
    public function run(array $argv): int
    {
        try {
            // Add default sources
            $this->addDefaultSources();

            if ($this->verbose) {
                $this->kernel->verbose(true);
            }

            $exitCode = $this->kernel->run($argv);

            return $exitCode->value;
        } catch (Throwable $e) {
            $this->console->error('Fatal Error: '.$e->getMessage());
            $this->console->line($e->getTraceAsString());

            return 255;
        }
    }

    /**
     * Run a directive by its signature.
     *
     * @param  string  $query  The signature (e.g., "tasks:process --verbose")
     * @return int The exit code
     */
    public function runSignature(string $query): int
    {
        try {
            $this->addDefaultSources();

            if ($this->verbose) {
                $this->kernel->verbose(true);
            }

            $exitCode = $this->kernel->runSignature($query);

            return $exitCode->value;
        } catch (Throwable $e) {
            $this->console->error('Fatal Error: '.$e->getMessage());
            $this->console->line($e->getTraceAsString());

            return 255;
        }
    }

    /**
     * Run a directive by its FQCN.
     *
     * @param  string  $fqcn  The fully qualified class name
     * @param  array<int, string>  $argv  The arguments
     * @return int The exit code
     */
    public function runDirective(string $fqcn, array $argv = []): int
    {
        try {
            $this->addDefaultSources();

            if ($this->verbose) {
                $this->kernel->verbose(true);
            }

            $exitCode = $this->kernel->runDirective($fqcn, $argv);

            return $exitCode->value;
        } catch (Throwable $e) {
            $this->console->error('Fatal Error: '.$e->getMessage());
            $this->console->line($e->getTraceAsString());

            return 255;
        }
    }

    /**
     * Enable or disable verbose mode.
     *
     * @param  bool  $enabled  Whether verbose mode is enabled
     */
    public function verbose(bool $enabled = true): self
    {
        $this->verbose = $enabled;

        if ($enabled) {
            $this->kernel->verbose(true);
        } else {
            $this->kernel->verbose(false);
        }

        return $this;
    }

    /**
     * Check if verbose mode is enabled.
     */
    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    /**
     * Get the underlying kernel instance.
     */
    public function getKernel(): DirectiveKernel
    {
        return $this->kernel;
    }

    /**
     * Get the underlying container instance.
     */
    public function getContainer(): TaskContainer
    {
        return $this->container;
    }

    /**
     * Add default directive sources.
     */
    private function addDefaultSources(): void
    {
        $basePath = $this->container->basePath();

        $this->kernel->addSource($basePath.'/src/Directives');
    }
}
