<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Directives\TasksProcessDirective;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

final class TasksProcessDirectiveTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private DirectiveTestingService $service;

    private UniqueTaskRepository $uniqueRepository;

    private RecurringTaskRepositoryInterface $recurringRepository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $frozenNow = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->service = new DirectiveTestingService(
            $this->app,
        );

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->uniqueRepository = new UniqueTaskRepository(
            $this->debugRepository,
            $this->app->make(LoggerInterface::class)
        );
        $this->recurringRepository = $this->app->make(RecurringTaskRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        $this->service->destroy();
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function getUuidForAlias(string $aliasName): string
    {
        return Uuid::uuid4()->toString();
    }

    private function generateAliasFromName(string $name, ?string $uuid = null): TaskAliasVO
    {
        $uuid = $uuid ?? $this->getUuidForAlias($name);

        return new TaskAliasVO('unique@'.$uuid);
    }

    private function createUniqueTask(
        string $alias,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3
    ): void {
        $scheduledAt = $scheduledAt ?? Carbon::now()->subHours(2);
        $id = $id ?? $this->getUuidForAlias($alias);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $this->generateAliasFromName($alias, $id),
            'fqcn' => new UniqueTaskFqcnVO(TestUniqueTask::class),
            'payload' => StrictDataObject::from(['test' => 'unique']),
            'scheduled_at' => new Iso8601DateTimeVO($scheduledAt->format('Y-m-d\TH:i:sP')),
            'grace_period_seconds' => new DurationVO($gracePeriodSeconds),
            'status' => $status,
            'attempts' => new CounterVO($attempts),
            'max_attempts' => new MaxFailedAttemptsVO($maxAttempts),
        ]);

        $this->uniqueRepository->create($record);
    }

    private function createFailingUniqueTask(): void
    {
        $alias = 'failing-task';
        $id = $this->getUuidForAlias($alias);

        $record = UniqueTaskRecord::from([
            'id' => $id,
            'alias' => $this->generateAliasFromName($alias, $id),
            'fqcn' => FailingTask::class,
            'payload' => ['test' => 'failing'],
            'scheduled_at' => Carbon::now()->subHours(2)->format('Y-m-d\TH:i:sP'),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING->value,
            'attempts' => 2,
            'max_attempts' => 3,
        ]);

        $this->uniqueRepository->create($record);
    }

    private function createRecurringTask(
        string $alias,
        RecurringTaskStatus $status = RecurringTaskStatus::PLAYING,
        ?\DateTimeInterface $startAt = null,
        ?\DateTimeInterface $lastRunAt = null,
        int $intervalSeconds = 3600
    ): void {
        $startAt = $startAt ?? Carbon::now()->subHours(2);
        $lastRunAt = $lastRunAt ?? null;
        $id = $this->getUuidForAlias($alias);

        $config = RecurringTaskConfigRecord::from([
            'type' => TaskType::RECURRING->value,
            'description' => 'Test recurring task',
            'interval_seconds' => $intervalSeconds,
            'start_at' => $startAt->format('Y-m-d\TH:i:sP'),
            'end_at' => Carbon::now()->addDays(7)->format('Y-m-d\TH:i:sP'),
            'max_attempts' => 3,
        ]);

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $payload = StrictDataObject::from(['test' => 'recurring']);

        $aliasVO = $service->register($fqcn, $payload, $config);

        $task = $this->recurringRepository->findByAlias($aliasVO);
        if ($task) {
            $this->recurringRepository->updateRaw(
                $task->getId()->getValue(),
                [
                    'status' => $status->value,
                    'start_at' => $startAt->format('Y-m-d H:i:s'),
                    'last_run_at' => $lastRunAt ? $lastRunAt->format('Y-m-d H:i:s') : null,
                ]
            );
        }
    }

    private function createFailingRecurringTask(): void
    {

        $config = RecurringTaskConfigRecord::from([
            'type' => TaskType::RECURRING->value,
            'description' => 'Failing recurring task',
            'interval_seconds' => 3000,
            'start_at' => Carbon::now()->subHours(2)->format('Y-m-d\TH:i:sP'),
            'end_at' => Carbon::now()->addDays(7)->format('Y-m-d\TH:i:sP'),
            'max_attempts' => 3,
        ]);

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $fqcn = new RecurringTaskFqcnVO(FailingRecurringTask::class);
        $payload = StrictDataObject::from(['should_fail' => true]);

        $aliasVO = $service->register($fqcn, $payload, $config);

        $task = $this->recurringRepository->findByAlias($aliasVO);
        if ($task) {
            $this->recurringRepository->updateRaw(
                $task->getId()->getValue(),
                [
                    'status' => RecurringTaskStatus::PLAYING->value,
                    'start_at' => Carbon::now()->subHours(2)->format('Y-m-d H:i:s'),
                    'last_run_at' => null,
                ]
            );
        }
    }

    // ==================== TESTS: Signature ====================

    public function test_get_signature_returns_correct_string(): void
    {
        $directive = $this->app->make(TasksProcessDirective::class);
        $signature = $directive->getSignature();

        $this->assertStringContainsString('tasks:process', $signature);
        $this->assertStringContainsString('--unique-only', $signature);
        $this->assertStringContainsString('--recurring-only', $signature);
        $this->assertStringContainsString('--verbose', $signature);
        $this->assertStringContainsString('limit=infinite', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $directive = $this->app->make(TasksProcessDirective::class);
        $description = $directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $directive = $this->app->make(TasksProcessDirective::class);
        $aliases = $directive->getAliases();

        $this->assertTrue($aliases->contains('task-process'));
        $this->assertTrue($aliases->contains('tp'));
        $this->assertSame(2, $aliases->count());
    }

    // ==================== TESTS: Basic Execution ====================

    public function test_execute_returns_success_when_no_tasks(): void
    {
        $response = $this->service->runDirective(TasksProcessDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('✅ Unique Success', $response->output);
        $this->assertStringContainsString('❌ Unique Failed', $response->output);
        $this->assertStringContainsString('✅ Recurring Success', $response->output);
        $this->assertStringContainsString('❌ Recurring Failed', $response->output);
        $this->assertStringContainsString('📊 Total Processed', $response->output);
    }

    public function test_execute_with_unique_only_flag(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->runDirective(TasksProcessDirective::class, ['--unique-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Unique Batch Results ===', $response->output);
        $this->assertStringContainsString('✅ Success', $response->output);
        $this->assertStringContainsString('❌ Failed', $response->output);
        $this->assertStringContainsString('📦 Total', $response->output);
    }

    public function test_execute_with_recurring_only_flag(): void
    {
        $this->createRecurringTask('recurring-1');

        $response = $this->service->runDirective(TasksProcessDirective::class, ['--recurring-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Recurring Batch Results ===', $response->output);
        $this->assertStringContainsString('✅ Success', $response->output);
        $this->assertStringContainsString('❌ Failed', $response->output);
        $this->assertStringContainsString('📦 Total', $response->output);
    }

    public function test_execute_with_both_flags_returns_invalid_argument(): void
    {
        $response = $this->service->runDirective(TasksProcessDirective::class, ['--unique-only', '--recurring-only']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Cannot use both --unique-only and --recurring-only', $response->output);
    }

    // ==================== TESTS: Limit ====================

    public function test_execute_with_limit_passes_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->runDirective(TasksProcessDirective::class, ['3', '--unique-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Limit', $response->output);
        $this->assertStringContainsString('3', $response->output);
        $this->assertStringContainsString('=== Unique Batch Results ===', $response->output);
        $this->assertStringContainsString('✅ Success', $response->output);
        $this->assertStringContainsString('❌ Failed', $response->output);
        $this->assertStringContainsString('📦 Total', $response->output);
    }

    public function test_execute_with_limit_zero_returns_success(): void
    {
        $response = $this->service->runDirective(
            TasksProcessDirective::class,
            ['0']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Limit', $response->output);
        $this->assertStringContainsString('infinite (no limit)', $response->output);
    }
    // ==================== TESTS: Context Storage ====================

    public function test_context_stores_unique_result(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->runDirective(TasksProcessDirective::class, ['--unique-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $context = $this->service->getKernel()->getContext();
        $this->assertNotNull($context);

        $found = false;
        foreach ($context as $key => $value) {
            if (str_starts_with($key, 'unique-')) {
                $found = true;
                $this->assertInstanceOf(TaskExecutionResultRecord::class, $value);
                $this->assertEquals(1, $value->success->getValue());
                $this->assertEquals(0, $value->failed->getValue());
                break;
            }
        }

        $this->assertTrue($found, 'Context should contain a unique result');
    }

    public function test_context_stores_recurring_result(): void
    {
        $this->createRecurringTask('recurring-1');

        $response = $this->service->runDirective(TasksProcessDirective::class, ['--recurring-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $context = $this->service->getKernel()->getContext();
        $this->assertNotNull($context);

        $found = false;
        foreach ($context as $key => $value) {
            if (str_starts_with($key, 'recurring-')) {
                $found = true;
                $this->assertInstanceOf(TaskExecutionResultRecord::class, $value);
                $this->assertEquals(1, $value->success->getValue());
                $this->assertEquals(0, $value->failed->getValue());
                break;
            }
        }

        $this->assertTrue($found, 'Context should contain a recurring result');
    }

    public function test_context_stores_both_results_in_full_mode(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->runDirective(TasksProcessDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $context = $this->service->getKernel()->getContext();
        $this->assertNotNull($context);

        $uniqueFound = false;
        $recurringFound = false;

        foreach ($context as $key => $value) {
            if (str_starts_with($key, 'unique-')) {
                $uniqueFound = true;
                $this->assertInstanceOf(TaskExecutionResultRecord::class, $value);
                $this->assertEquals(1, $value->success->getValue());
            }

            if (str_starts_with($key, 'recurring-')) {
                $recurringFound = true;
                $this->assertInstanceOf(TaskExecutionResultRecord::class, $value);
                $this->assertEquals(1, $value->success->getValue());
            }
        }

        $this->assertTrue($uniqueFound, 'Context should contain a unique result');
        $this->assertTrue($recurringFound, 'Context should contain a recurring result');
    }

    public function test_context_stores_errors_in_record(): void
    {
        $this->createFailingUniqueTask();

        $response = $this->service->runDirective(TasksProcessDirective::class, ['--unique-only']);

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);

        $context = $this->service->getKernel()->getContext();
        $this->assertNotNull($context);

        $found = false;
        foreach ($context as $key => $value) {
            if (str_starts_with($key, 'unique-')) {
                $found = true;
                $this->assertInstanceOf(TaskExecutionResultRecord::class, $value);
                $this->assertEquals(0, $value->success->getValue());
                $this->assertEquals(1, $value->failed->getValue());
                $this->assertTrue($value->has_failures);
                $this->assertGreaterThan(0, $value->errors->count());
                break;
            }
        }

        $this->assertTrue($found, 'Context should contain a unique result with errors');
    }

    public function test_context_stores_multiple_executions_with_different_uuids(): void
    {
        $this->createUniqueTask('unique-1');

        // Première exécution
        $response1 = $this->service->runDirective(TasksProcessDirective::class, ['--unique-only']);
        $this->assertSame(ExitCode::SUCCESS, $response1->exit_code);

        $context1 = $this->service->getKernel()->getContext();

        // Deuxième exécution
        $response2 = $this->service->runDirective(TasksProcessDirective::class, ['--unique-only']);
        $this->assertSame(ExitCode::SUCCESS, $response2->exit_code);

        $context2 = $this->service->getKernel()->getContext();

        // Vérifier que les deux résultats sont dans le contexte
        $uniqueKeys1 = [];
        $uniqueKeys2 = [];

        foreach ($context1 as $key => $value) {
            if (str_starts_with($key, 'unique-')) {
                $uniqueKeys1[] = $key;
            }
        }

        foreach ($context2 as $key => $value) {
            if (str_starts_with($key, 'unique-')) {
                $uniqueKeys2[] = $key;
            }
        }

        // Le contexte 2 devrait avoir un élément de plus que le contexte 1
        $this->assertGreaterThan(count($uniqueKeys1), count($uniqueKeys2));
    }

    // ==================== TESTS: Verbose ====================

    public function test_verbose_output_shows_errors(): void
    {
        $this->createFailingUniqueTask();

        $response = $this->service->runDirective(
            TasksProcessDirective::class,
            ['--verbose']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('=== Failed Tasks ===', $response->output);
        $this->assertStringContainsString('Task', $response->output);
        $this->assertStringContainsString('Context', $response->output);
        $this->assertStringContainsString('Description', $response->output);
    }

    public function test_verbose_output_without_errors(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->runDirective(
            TasksProcessDirective::class,
            ['--verbose']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringNotContainsString('=== Failed Tasks ===', $response->output);
    }

    public function test_verbose_output_for_full_mode_shows_both_errors(): void
    {
        $this->createFailingUniqueTask();
        $this->createFailingRecurringTask();

        $response = $this->service->runDirective(
            TasksProcessDirective::class,
            ['--verbose']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('=== Failed Tasks ===', $response->output);
        $this->assertStringContainsString('Unique tasks:', $response->output);
        $this->assertStringContainsString('Recurring tasks:', $response->output);
        $this->assertStringContainsString('Task', $response->output);
        $this->assertStringContainsString('Context', $response->output);
        $this->assertStringContainsString('Description', $response->output);
    }

    // ==================== TESTS: Output Format ====================

    public function test_text_output_contains_batch_results(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->runDirective(TasksProcessDirective::class, []);

        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('✅ Unique Success', $response->output);
        $this->assertStringContainsString('❌ Unique Failed', $response->output);
        $this->assertStringContainsString('✅ Recurring Success', $response->output);
        $this->assertStringContainsString('❌ Recurring Failed', $response->output);
        $this->assertStringContainsString('📊 Total Processed', $response->output);
    }

    public function test_text_output_shows_limit_message(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->runDirective(
            TasksProcessDirective::class,
            ['3', '--unique-only']
        );

        $this->assertStringContainsString('Limit', $response->output);
        $this->assertStringContainsString('3', $response->output);
        $this->assertStringContainsString('=== Unique Batch Results ===', $response->output);
        $this->assertStringContainsString('✅ Success', $response->output);
        $this->assertStringContainsString('❌ Failed', $response->output);
        $this->assertStringContainsString('📦 Total', $response->output);
    }

    public function test_full_mode_text_output_shows_combined_results(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->runDirective(TasksProcessDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('✅ Unique Success', $response->output);
        $this->assertStringContainsString('❌ Unique Failed', $response->output);
        $this->assertStringContainsString('✅ Recurring Success', $response->output);
        $this->assertStringContainsString('❌ Recurring Failed', $response->output);
        $this->assertStringContainsString('📊 Total Processed', $response->output);
    }

    public function test_full_mode_with_errors_shows_failures(): void
    {
        $this->createFailingUniqueTask();
        $this->createRecurringTask('recurring-1');

        $response = $this->service->runDirective(TasksProcessDirective::class, []);

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('✅ Unique Success', $response->output);
        $this->assertStringContainsString('❌ Unique Failed', $response->output);
        $this->assertStringContainsString('✅ Recurring Success', $response->output);
        $this->assertStringContainsString('❌ Recurring Failed', $response->output);
        $this->assertStringContainsString('📊 Total Processed', $response->output);
    }
}
