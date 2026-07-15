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
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Tests\Fixtures\Tasks\HelloRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\HelloUniqueTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use Illuminate\Support\Facades\DB;

/**
 * Directive to register fixture tasks for testing.
 *
 * Registers HelloUniqueTask and HelloRecurringTask instances.
 * Use unique_count=N and recurring_count=M to control how many tasks to create.
 */
final class RegisterFixtureTasksDirective extends AbstractDirective
{
    private const DEFAULT_UNIQUE_COUNT = 1;

    private const DEFAULT_RECURRING_COUNT = 1;

    public function getSignature(): string
    {
        return 'fixture:register-tasks {unique_count=?} {recurring_count=?}';
    }

    public function getDescription(): string
    {
        return 'Register fixture tasks for testing. Use unique_count=N and recurring_count=M to control task count.';
    }

    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from(['fixture:tasks']);
    }

    protected function execute(): ExitCode
    {
        $console = $this->getConsole();
        $app = $this->getApplication();

        /** @var UniqueTaskRepository $uniqueTaskRepository */
        $uniqueTaskRepository = $app->make(UniqueTaskRepository::class);

        /** @var RecurringTaskRepository $recurringTaskRepository */
        $recurringTaskRepository = $app->make(RecurringTaskRepository::class);

        $uniqueTaskService = $app->make(UniqueTaskServiceInterface::class);
        $recurringTaskService = $app->make(RecurringTaskServiceInterface::class);

        // ✅ Récupérer les paramètres avec vérification explicite
        $uniqueRaw = $this->getArgument('unique_count');
        $recurringRaw = $this->getArgument('recurring_count');

        $uniqueCount = $uniqueRaw !== null && $uniqueRaw !== '' ? (int) $uniqueRaw : self::DEFAULT_UNIQUE_COUNT;
        $recurringCount = $recurringRaw !== null && $recurringRaw !== '' ? (int) $recurringRaw : self::DEFAULT_RECURRING_COUNT;

        // ✅ Valider les paramètres
        if ($uniqueCount < 0) {
            $console->error('❌ unique_count must be >= 0');

            return ExitCode::INVALID_ARGUMENT;
        }

        if ($recurringCount < 0) {
            $console->error('❌ recurring_count must be >= 0');

            return ExitCode::INVALID_ARGUMENT;
        }

        if ($uniqueCount === 0 && $recurringCount === 0) {
            $console->error('❌ At least one of unique_count or recurring_count must be > 0');

            return ExitCode::INVALID_ARGUMENT;
        }

        $console->info('📝 Registering fixture tasks...');
        $console->line();

        try {
            // ✅ 1. Vider la base de données
            $console->info('🗑️ Clearing existing tasks...');

            $uniqueDeleted = DB::table('unique_tasks')->delete();
            $recurringDeleted = DB::table('recurring_tasks')->delete();

            // ✅ Vider la table de debug
            $debugDeleted = DB::table('task_execution_debugs')->delete();

            // ✅ Réinitialiser les AUTO_INCREMENT pour SQLite
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::statement('DELETE FROM sqlite_sequence WHERE name="unique_tasks"');
                DB::statement('DELETE FROM sqlite_sequence WHERE name="recurring_tasks"');
                DB::statement('DELETE FROM sqlite_sequence WHERE name="task_execution_debugs"');
            }

            $console->line(sprintf('   🗑️  Deleted %d unique tasks', $uniqueDeleted));
            $console->line(sprintf('   🗑️  Deleted %d recurring tasks', $recurringDeleted));
            $console->line(sprintf('   🗑️  Deleted %d debug records', $debugDeleted));
            $console->line();

            $totalRegistered = 0;

            // ✅ 2. Enregistrer les tâches uniques (scheduled dans 10s)
            if ($uniqueCount > 0) {
                $console->info(sprintf('📝 Registering %d unique task(s)...', $uniqueCount));

                for ($i = 1; $i <= $uniqueCount; $i++) {
                    $uniqueConfig = UniqueTaskConfigRecord::from([
                        'scheduled_at' => new Iso8601DateTimeVO(now()->addSeconds(10)->toIso8601String()),
                        'max_attempts' => 3,
                        'grace_period' => 3600,
                        'description' => new DescriptionVO(sprintf('Fixture unique task #%d', $i)),
                    ]);

                    $uniquePayload = StrictDataObject::from([
                        'message' => sprintf('Hello from unique task #%d', $i),
                        'timestamp' => now()->toIso8601String(),
                        'task_id' => $i,
                        'type' => 'unique',
                    ]);

                    $uniqueAlias = $uniqueTaskService->register(
                        new UniqueTaskFqcnVO(HelloUniqueTask::class),
                        $uniquePayload,
                        $uniqueConfig
                    );

                    $totalRegistered++;

                    if ($i % 100 === 0 || $i === $uniqueCount) {
                        $console->line(sprintf('   Progress: %d/%d unique tasks registered', $i, $uniqueCount));
                    }
                }

                $console->success(sprintf('✅ %d unique task(s) registered', $uniqueCount));
                $console->line();
            }

            // ✅ 3. Enregistrer les tâches récurrentes avec start_at et end_at décalés
            if ($recurringCount > 0) {
                $console->info(sprintf('📝 Registering %d recurring task(s)...', $recurringCount));

                // ✅ Tableaux des valeurs décalées
                $startAtOffsets = [];
                $endAtValues = [];

                // ✅ Générer les offsets de start_at : 0, 10, 20, 30, 0, 10, 20, 30, ...
                for ($i = 0; $i < $recurringCount; $i++) {
                    $startAtOffsets[] = ($i % 4) * 10; // 0, 10, 20, 30
                }

                // ✅ Générer les end_at : 60, 70, 80, 90, 100, 110, 120, 60, 70, 80, ...
                for ($i = 0; $i < $recurringCount; $i++) {
                    $endAtValues[] = 60 + (($i % 7) * 10); // 60, 70, 80, 90, 100, 110, 120
                }

                for ($i = 1; $i <= $recurringCount; $i++) {
                    $startAtOffset = $startAtOffsets[$i - 1];
                    $endAtSeconds = $endAtValues[$i - 1];

                    $startAt = now()->addSeconds($startAtOffset);
                    $endAt = now()->addSeconds($startAtOffset + $endAtSeconds);

                    $recurringConfig = RecurringTaskConfigRecord::from([
                        'interval_seconds' => 15,
                        'start_at' => $startAt->toIso8601String(),
                        'end_at' => $endAt->toIso8601String(),
                        'max_attempts' => 3,
                        'description' => new DescriptionVO(sprintf(
                            'Fixture recurring task #%d (start_at: +%ds, end_at: +%ds, interval: 15s)',
                            $i,
                            $startAtOffset,
                            $startAtOffset + $endAtSeconds
                        )),
                    ]);

                    $recurringPayload = StrictDataObject::from([
                        'message' => sprintf('Hello from recurring task #%d', $i),
                        'timestamp' => now()->toIso8601String(),
                        'task_id' => $i,
                        'type' => 'recurring',
                        'start_at_offset' => $startAtOffset,
                        'end_at_seconds' => $startAtOffset + $endAtSeconds,
                    ]);

                    $recurringAlias = $recurringTaskService->register(
                        new RecurringTaskFqcnVO(HelloRecurringTask::class),
                        $recurringPayload,
                        $recurringConfig
                    );

                    $totalRegistered++;

                    if ($i % 100 === 0 || $i === $recurringCount) {
                        $console->line(sprintf('   Progress: %d/%d recurring tasks registered', $i, $recurringCount));
                    }
                }

                $console->success(sprintf('✅ %d recurring task(s) registered', $recurringCount));
                $console->line();
            }

            // ✅ 4. Résumé final
            $console->title('📊 Registration Summary');
            $console->line();
            $console->line(sprintf('  ✅ Unique tasks: %d', $uniqueCount));
            $console->line(sprintf('  ✅ Recurring tasks: %d', $recurringCount));
            $console->line(sprintf('  📦 Total tasks: %d', $totalRegistered));
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
