<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Services\TaskRegistryService;

/**
 * Console directive for unregistering tasks (unique or recurring).
 *
 * @example ./vendor/bin/directive task-unregister 550e8400-e29b-41d4-a716-446655440000
 * @example ./vendor/bin/directive task-unregister clear-unconfirmed-orders --force
 */
final class TaskUnregisterDirective extends AbstractDirective
{
    public function __construct(
        DirectiveContext $context,
        DirectiveInteractionService $interaction,
    ) {
        parent::__construct($context, $interaction);
    }

    public function getSignature(): string
    {
        return 'task-unregister {identifier?} {--force}';
    }

    public function shouldBootLaravel(): bool
    {
        return true;
    }

    public function getDescription(): string
    {
        return 'Unregister a task (unique or recurring)';
    }

    public function getAliases(): StringTypedCollection
    {
        $aliases = new StringTypedCollection;
        $aliases->add('unregister-task');

        return $aliases;
    }

    public function execute(): ExitCode
    {
        $identifier = $this->argument('identifier');

        if ($identifier === null) {
            $this->error('Task identifier is required');

            return ExitCode::INVALID_ARGUMENT;
        }

        if (! $this->hasOption('force')) {
            $confirmed = $this->confirm(
                sprintf("Are you sure you want to unregister task '%s'? This action cannot be undone.", $identifier)
            );

            if (! $confirmed) {
                $this->info('Operation cancelled.');

                return ExitCode::SUCCESS;
            }
        }

        try {
            $this->getRegistryService()->unregister($identifier);
            $this->info(sprintf("Task '%s' has been unregistered successfully.", $identifier));

            return ExitCode::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return ExitCode::FAILURE;
        }
    }

    private function getRegistryService(): TaskRegistryService
    {
        return $this->getLaravel()->make(TaskRegistryService::class);
    }
}
