<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Directives\TasksWatchDirective;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

final class TasksWatchDirectiveTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private DirectiveTestingService $service;

    private UniqueTaskRepository $uniqueRepository;

    private RecurringTaskRepositoryInterface $recurringRepository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runDatabaseMigrations();

        $this->service = new DirectiveTestingService($this->app);

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->uniqueRepository = new UniqueTaskRepository($this->debugRepository);
        $this->recurringRepository = $this->app->make(RecurringTaskRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $this->service->destroy();
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function createUniqueTask(
        string $alias,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400
    ): void {
        $scheduledAt = $scheduledAt ?? Carbon::now()->subHours(2);
        $id = $id ?? (string) Uuid::uuid4();

        $record = UniqueTaskRecord::from([
            'id' => $id,
            'alias' => $alias,
            'fqcn' => TestUniqueTask::class,
            'payload' => ['test' => 'unique'],
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:sP'),
            'grace_period_seconds' => $gracePeriodSeconds,
            'status' => $status,
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $this->uniqueRepository->create($record);
    }

    private function createFailingUniqueTask(): void
    {
        $id = (string) Uuid::uuid4();

        $record = UniqueTaskRecord::from([
            'id' => $id,
            'alias' => 'failing-task',
            'fqcn' => FailingTask::class,
            'payload' => ['test' => 'failing'],
            'scheduled_at' => Carbon::now()->subHours(2)->format('Y-m-d\TH:i:sP'),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
            'attempts' => 2,
            'max_attempts' => 3,
        ]);

        $this->uniqueRepository->create($record);
    }

    private function createRecurringTask(
        string $alias,
        RecurringTaskStatus $status = RecurringTaskStatus::PLAYING,
        ?\DateTimeInterface $startAt = null,
        ?\DateTimeInterface $lastRunAt = null
    ): void {
        $startAt = $startAt ?? Carbon::now()->subMinutes(10);
        $lastRunAt = $lastRunAt ?? Carbon::now()->subMinutes(10);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO($alias),
            description: 'Test recurring task',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO($startAt->format('Y-m-d\TH:i:sP')),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(7)->format('Y-m-d\TH:i:sP')),
            max_attempts: new CounterVO(3),
        );

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $aliasVO = $service->register(
            TestRecurringTask::class,
            StrictDataObject::from(['test' => 'recurring']),
            $config
        );

        $task = $this->recurringRepository->findByAlias($aliasVO->value);
        if ($task) {
            $task->status = $status;
            $task->start_at = $startAt;
            $task->last_run_at = $lastRunAt;
            $task->save();
        }
    }

    private function createFailingRecurringTask(): void
    {
        $frozenNow = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring-failing'),
            description: 'Test recurring task that fails',
            interval_seconds: new CounterVO(3),
            start_at: new Iso8601DateTimeVO($frozenNow->copy()->subHours(2)->toIso8601String()),
            max_attempts: new CounterVO(1),
        );

        /** @var RecurringTaskServiceInterface $service */
        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $service->register(
            FailingRecurringTask::class,
            StrictDataObject::from([
                'should_fail' => true,
                'fail_message' => 'Test recurring failure',
            ]),
            $config
        );
    }

    // ==================== TESTS: Signature ====================

    public function test_get_signature_returns_correct_string(): void
    {
        $directive = $this->app->make(TasksWatchDirective::class);
        $signature = $directive->getSignature();

        $this->assertStringContainsString('tasks-watch', $signature);
        $this->assertStringContainsString('--duration=', $signature);
        $this->assertStringContainsString('--interval=', $signature);
        $this->assertStringContainsString('--unique-only', $signature);
        $this->assertStringContainsString('--recurring-only', $signature);
        $this->assertStringContainsString('--limit=', $signature);
        $this->assertStringContainsString('--verbose', $signature);
        $this->assertStringContainsString('--testing', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $directive = $this->app->make(TasksWatchDirective::class);
        $description = $directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('interval', $description);
        $this->assertStringContainsString('seconds', $description);
        $this->assertStringContainsString('3', $description);
        $this->assertStringContainsString('testing', $description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $directive = $this->app->make(TasksWatchDirective::class);
        $aliases = $directive->getAliases();

        $this->assertTrue($aliases->contains('task-watch'));
        $this->assertTrue($aliases->contains('tasks-watch'));
        $this->assertSame(2, $aliases->count());
    }

    // ==================== TESTS: Validation ====================

    public function test_execute_with_both_flags_returns_invalid_argument(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--unique-only', '--recurring-only']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Cannot use both --unique-only and --recurring-only', $response->output);
    }

    public function test_execute_with_limit_zero_returns_invalid_argument(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--limit=0']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Limit must be a positive integer', $response->output);
    }

    public function test_execute_with_limit_negative_returns_invalid_argument(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--limit=-5']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Limit must be a positive integer', $response->output);
    }

    public function test_execute_with_interval_below_minimum_returns_invalid_argument(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--interval=2']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Interval must be at least 3 seconds', $response->output);
    }

    public function test_execute_with_interval_zero_returns_invalid_argument(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--interval=0']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Interval must be at least 3 seconds', $response->output);
    }

    public function test_execute_with_duration_zero_returns_invalid_argument(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--duration=0']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Duration must be a positive integer', $response->output);
    }

    public function test_execute_with_duration_negative_returns_invalid_argument(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--duration=-10']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Duration must be a positive integer', $response->output);
    }

    // ==================== TESTS: Exécution en mode testing ====================

    public function test_execute_testing_mode_returns_success_when_no_tasks(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--duration=2', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('🔬 Mode: TESTING', $response->output);
        $this->assertStringContainsString('🚀 Starting tasks watch loop...', $response->output);
        $this->assertStringContainsString('Duration: 2 seconds', $response->output);
        $this->assertStringContainsString('Interval: 3 seconds', $response->output);
        $this->assertStringContainsString('📊 === Summary ===', $response->output);
        $this->assertStringContainsString('Cycles executed:  1', $response->output);
        $this->assertStringContainsString('Total success:    0', $response->output);
        $this->assertStringContainsString('Total failures:   0', $response->output);
    }

    public function test_execute_testing_mode_with_unique_only_flag(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--unique-only', '--duration=2', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('🔬 Mode: TESTING', $response->output);
        $this->assertStringContainsString('Options: --unique-only', $response->output);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertStringContainsString('Total success:    1', $response->output);
        $this->assertStringContainsString('Total failures:   0', $response->output);
    }

    public function test_execute_testing_mode_with_recurring_only_flag(): void
    {
        $frozenNow = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('recurring-1'),
            description: 'Test recurring task',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO($frozenNow->copy()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO($frozenNow->copy()->addDays(7)->toIso8601String()),
            max_attempts: new CounterVO(3),
        );

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $service->register(
            TestRecurringTask::class,
            StrictDataObject::from(['test' => 'recurring']),
            $config
        );

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--recurring-only', '--duration=2', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Options: --recurring-only', $response->output);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertStringContainsString('Total success:    1', $response->output);
        $this->assertStringContainsString('Total failures:   0', $response->output);
    }

    public function test_execute_testing_mode_with_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--unique-only', '--limit=3', '--duration=2', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Options: --unique-only --limit=3', $response->output);
        $this->assertStringContainsString('✅ 3 tasks succeeded', $response->output);
        $this->assertStringContainsString('Total success:    3', $response->output);
        $this->assertStringContainsString('Total failures:   0', $response->output);
    }

    public function test_execute_testing_mode_with_interval_exactly_minimum_works(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--interval=3', '--unique-only', '--duration=2']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Interval: 3 seconds', $response->output);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertStringContainsString('Total success:    1', $response->output);
    }

    public function test_execute_testing_mode_with_duration_stops_after_duration(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--duration=2', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Duration: 2 seconds', $response->output);
        $this->assertStringContainsString('⏰ Duration reached. Stopping gracefully...', $response->output);
        $this->assertStringContainsString('Cycles executed:  1', $response->output);
    }

    public function test_execute_testing_mode_with_errors_returns_failure(): void
    {
        $this->createFailingUniqueTask();

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--duration=2', '--interval=3']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('❌ 1 tasks failed', $response->output);
        $this->assertStringContainsString('Total failures:   1', $response->output);
        $this->assertStringContainsString('Total errors:     1', $response->output);
    }

    public function test_execute_testing_mode_with_recurring_errors_returns_failure(): void
    {
        $this->createFailingRecurringTask();

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--duration=5', '--interval=3']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('❌ 1 tasks failed', $response->output);
        $this->assertStringContainsString('Total failures:   2', $response->output);
        $this->assertStringContainsString('Total errors:     2', $response->output);
    }

    public function test_execute_testing_mode_with_mixed_success_and_errors(): void
    {
        $this->createUniqueTask('unique-success');
        $this->createFailingUniqueTask();

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--duration=2', '--interval=3']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertStringContainsString('❌ 1 tasks failed', $response->output);
        $this->assertStringContainsString('Total success:    1', $response->output);
        $this->assertStringContainsString('Total failures:   1', $response->output);
        $this->assertStringContainsString('Total errors:     1', $response->output);
    }

    public function test_execute_testing_mode_runs_multiple_cycles(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--unique-only', '--limit=3', '--duration=12', '--interval=4']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $this->assertStringContainsString('🔄 Cycle #1', $response->output);
        $this->assertStringContainsString('🔄 Cycle #2', $response->output);
        $this->assertStringContainsString('🔄 Cycle #3', $response->output);

        $this->assertStringContainsString('Cycles executed:  3', $response->output);
        $this->assertStringContainsString('Total success:    6', $response->output);
        $this->assertStringContainsString('Total failures:   0', $response->output);
    }
}
