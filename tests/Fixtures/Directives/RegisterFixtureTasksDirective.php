<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\Tests\Fixtures\Tasks\HelloRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\HelloUniqueTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;

/**
 * Directive to register fixture tasks for testing.
 *
 * Registers HelloUniqueTask and HelloRecurringTask for manual testing.
 */
final class RegisterFixtureTasksDirective extends AbstractDirective
{
    public function getSignature(): string
    {
        return 'fixture:register-tasks';
    }

    public function getDescription(): string
    {
        return 'Register fixture tasks for testing (HelloUniqueTask and HelloRecurringTask)';
    }

    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from(['fixture:tasks']);
    }

    protected function execute(): ExitCode
    {
        $console = $this->getConsole();
        $app = $this->getApplication();

        $uniqueTaskService = $app->make(UniqueTaskServiceInterface::class);
        $recurringTaskService = $app->make(RecurringTaskServiceInterface::class);

        $console->info('📝 Registering fixture tasks...');
        $console->line();

        try {
            // ✅ 1. Enregistrer la tâche unique
            $uniqueConfig = UniqueTaskConfigRecord::from([
                'scheduled_at' => new Iso8601DateTimeVO(now()->addSeconds(5)->toIso8601String()),
                'max_attempts' => 3,
                'grace_period' => 3600,
                'description' => new DescriptionVO('Fixture unique task - HelloWorld'),
            ]);

            $uniquePayload = StrictDataObject::from([
                'message' => 'Hello from unique task',
                'timestamp' => now()->toIso8601String(),
            ]);

            $uniqueAlias = $uniqueTaskService->register(
                new UniqueTaskFqcnVO(HelloUniqueTask::class),
                $uniquePayload,
                $uniqueConfig
            );

            $console->success('✅ Unique task registered:');
            $console->line("   Alias: {$uniqueAlias->getValue()}");
            $console->line('   Class: '.HelloUniqueTask::class);
            $console->line('   Scheduled: in 5 seconds');
            $console->line();

            // ✅ 2. Enregistrer la tâche récurrente
            $recurringConfig = RecurringTaskConfigRecord::from([
                'interval_seconds' => 10,
                'start_at' => now()->toIso8601String(),
                'max_attempts' => 3,
                'description' => new DescriptionVO('Fixture recurring task - HelloWorld'),
            ]);

            $recurringPayload = StrictDataObject::from([
                'message' => 'Hello from recurring task',
                'timestamp' => now()->toIso8601String(),
            ]);

            $recurringAlias = $recurringTaskService->register(
                new RecurringTaskFqcnVO(HelloRecurringTask::class),
                $recurringPayload,
                $recurringConfig
            );

            $console->success('✅ Recurring task registered:');
            $console->line("   Alias: {$recurringAlias->getValue()}");
            $console->line('   Class: '.HelloRecurringTask::class);
            $console->line('   Interval: every 10 seconds');
            $console->line();

            $console->info('📋 To execute the tasks, run:');
            $console->line('   ./vendor/bin/directive process-tasks');
            $console->line('   or');
            $console->line('   ./vendor/bin/directive tasks-watch');
            $console->line();

            return ExitCode::SUCCESS;

        } catch (\Throwable $e) {
            $console->error('❌ Failed to register fixture tasks: '.$e->getMessage());
            $console->line();

            return ExitCode::RUNTIME_ERROR;
        }
    }
}
