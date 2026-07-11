<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts;

use AndyDefer\Directive\Container\Container;
use AndyDefer\Directive\DirectiveKernel;

/**
 * Interface for the Task Application.
 *
 * Defines the contract for running task directives from within your application
 * or from the command line. Provides methods for executing directives by signature,
 * FQCN, or command-line arguments.
 */
interface ApplicationInterface
{
    /**
     * Create a new application instance.
     *
     * @param  string  $basePath  The project root path
     */
    public static function create(string $basePath): static;

    /**
     * Run the task application with command-line arguments.
     *
     * @param  array<int, string>  $argv  The command-line arguments
     * @return int The exit code
     */
    public function run(array $argv): int;

    /**
     * Run a directive by its signature.
     *
     * @param  string  $query  The signature (e.g., "tasks:process --verbose")
     * @return int The exit code
     */
    public function runSignature(string $query): int;

    /**
     * Run a directive by its FQCN.
     *
     * @param  string  $fqcn  The fully qualified class name
     * @param  array<int, string>  $argv  The arguments
     * @return int The exit code
     */
    public function runDirective(string $fqcn, array $argv = []): int;

    /**
     * Enable or disable verbose mode.
     *
     * @param  bool  $enabled  Whether verbose mode is enabled
     */
    public function verbose(bool $enabled = true): static;

    /**
     * Check if verbose mode is enabled.
     */
    public function isVerbose(): bool;

    /**
     * Get the underlying kernel instance.
     */
    public function getKernel(): DirectiveKernel;

    /**
     * Get the underlying container instance.
     */
    public function getContainer(): Container;
}
