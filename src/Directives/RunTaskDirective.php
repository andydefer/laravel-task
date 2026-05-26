<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Directive\Services\LaravelBootstrapper;
use AndyDefer\Logger\Logger;
use AndyDefer\Records\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Services\ProcessManager;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;

final class RunTaskDirective extends AbstractDirective
{
    private ?ProcessManager $processManager = null;

    public function __construct(
        DirectiveInteractionService $interaction,
        private readonly TaskStorage $storage,
        private readonly TaskRunner $runner,
        private readonly TaskValidator $validator,
        private readonly Logger $logger,
        ?LaravelBootstrapper $laravelBootstrapper = null,
        ?ProcessManager $processManager = null,
    ) {
        parent::__construct($interaction, $laravelBootstrapper);
        $this->processManager = $processManager;
    }

    public function getSignature(): string
    {
        return 'run-task {--duration=60 : Max execution time in seconds} {--dry-run : Simulate without executing} {--no-fork : Disable forking (sequential execution)} {--lock-path= : Custom lock file path}';
    }

    public function getDescription(): string
    {
        return 'Run pending and recurring tasks for the specified duration';
    }

    public function getAliases(): StringTypedCollection
    {
        $aliases = new StringTypedCollection;
        $aliases->add('task-run');
        $aliases->add('tasks-run');

        return $aliases;
    }

    public function shouldBootLaravel(): bool
    {
        return true;
    }

    public function execute(): ExitCode
    {
        $duration = (int) ($this->option('duration') ?? 60);
        $dryRun = $this->hasOption('dry-run');
        $noFork = $this->hasOption('no-fork');
        $lockPath = $this->option('lock-path');

        if ($dryRun) {
            $this->warn('Dry run mode - no tasks will be executed');
        }

        $this->info("Starting task poller for {$duration} seconds...");

        $useSequentialMode = $noFork || ! function_exists('pcntl_fork');

        if ($useSequentialMode && ! $noFork) {
            $this->warn('pcntl_fork not available, falling back to sequential mode');
        }

        $manager = $this->processManager ?? new ProcessManager(
            runner: $this->runner,
            storage: $this->storage,
            logger: $this->logger,
            validator: $this->validator,
            lockPath: $lockPath ?: null,
            useSequentialMode: $useSequentialMode,
        );

        $manager->run($duration, $dryRun);

        $this->info('Task poller finished');

        return ExitCode::SUCCESS;
    }
}
