<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Services\TaskBatch;

/**
 * Process tasks in a single batch.
 * 
 * @author Andy Defer
 */
final class ProcessTasksDirective extends AbstractDirective
{
    public function __construct(
        DirectiveInteractionService $interaction,
        private readonly TaskBatch $batch,
    ) {
        parent::__construct($interaction);
    }

    public function getSignature(): string
    {
        return 'process-tasks {--unique-only : Process only unique tasks} {--recurring-only : Process only recurring tasks} {--verbose : Show detailed task results} {--limit= : Maximum number of tasks to process}';
    }

    public function getDescription(): string
    {
        return 'Process all pending tasks in a single batch (no polling, no waiting)';
    }

    public function getAliases(): StringTypedCollection
    {
        $aliases = new StringTypedCollection();
        $aliases->add('task:process');
        $aliases->add('tasks:process');
        return $aliases;
    }

    public function execute(): ExitCode
    {
        $uniqueOnly = $this->hasOption('unique-only');
        $recurringOnly = $this->hasOption('recurring-only');
        $verbose = $this->hasOption('verbose');
        $limit = $this->option('limit');

        if ($uniqueOnly && $recurringOnly) {
            $this->error('Cannot use both --unique-only and --recurring-only');
            return ExitCode::INVALID_ARGUMENT;
        }

        $limitValue = $limit !== null ? (int) $limit : null;

        if ($limitValue !== null && $limitValue <= 0) {
            $this->error('Limit must be a positive integer');
            return ExitCode::INVALID_ARGUMENT;
        }

        $this->info('Processing tasks...');
        if ($limitValue !== null) {
            $this->info("Limit: {$limitValue} tasks");
        }

        if ($uniqueOnly) {
            $result = $this->batch->processUniqueOnly($limitValue);
        } elseif ($recurringOnly) {
            $result = $this->batch->processRecurringOnly($limitValue);
        } else {
            $result = $this->batch->process($limitValue);
        }

        // Display summary
        $this->info('');
        $this->info('<fg=cyan>=== Batch Results ===</>');
        $this->info(sprintf(
            '  Unique tasks:   %d processed (✅ %d, ❌ %d)',
            $result->getUniqueSuccess() + $result->getUniqueFailed(),
            $result->getUniqueSuccess(),
            $result->getUniqueFailed()
        ));
        $this->info(sprintf(
            '  Recurring tasks: %d processed (✅ %d, ❌ %d)',
            $result->getRecurringSuccess() + $result->getRecurringFailed(),
            $result->getRecurringSuccess(),
            $result->getRecurringFailed()
        ));
        $this->info(sprintf(
            '  Total:          %d tasks in %d ms',
            $result->getTotal(),
            $result->getDurationMilliseconds()
        ));

        // Show errors if verbose
        if ($verbose && $result->hasFailures()) {
            $this->info('');
            $this->info('<fg=red>=== Failed Tasks ===</>');
            foreach ($result->getErrors() as $id => $error) {
                $this->info(sprintf('  ❌ %s: %s', $id, $error));
            }
        }

        if ($result->isSuccessful()) {
            return ExitCode::SUCCESS;
        } elseif ($result->hasFailures() && $result->getTotalSuccess() > 0) {
            return ExitCode::FAILURE;
        } else {
            return ExitCode::FAILURE;
        }
    }
}
