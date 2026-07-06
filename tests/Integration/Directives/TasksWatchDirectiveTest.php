<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Directives\TasksWatchDirective;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
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

        $this->service = new DirectiveTestingService($this->app);

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->uniqueRepository = new UniqueTaskRepository(
            $this->debugRepository,
            $this->app->make(LoggerInterface::class)
        );
        $this->recurringRepository = $this->app->make(RecurringTaskRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
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
            'id' => new UuidVO($id),
            'alias' => $this->generateAliasFromName($alias, $id),
            'fqcn' => new UniqueTaskFqcnVO(FailingTask::class),
            'payload' => StrictDataObject::from(['test' => 'failing']),
            'scheduled_at' => new Iso8601DateTimeVO(Carbon::now()->subHours(2)->format('Y-m-d\TH:i:sP')),
            'grace_period_seconds' => new DurationVO(86400),
            'status' => UniqueTaskStatus::PENDING,
            'attempts' => new CounterVO(2),
            'max_attempts' => new MaxFailedAttemptsVO(3),
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
        $lastRunAt = $lastRunAt ?? Carbon::now()->subHours(2);
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
                    'last_run_at' => $lastRunAt->format('Y-m-d H:i:s'),
                ]
            );
        }
    }

    private function createFailingRecurringTask(): void
    {
        $frozenNow = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = 'test-recurring-failing';
        $id = $this->getUuidForAlias($alias);

        $config = RecurringTaskConfigRecord::from([
            'type' => TaskType::RECURRING->value,
            'description' => 'Test recurring task that fails',
            'interval_seconds' => 3,
            'start_at' => $frozenNow->copy()->subHours(2)->format('Y-m-d\TH:i:sP'),
            'max_attempts' => 1,
        ]);
        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $fqcn = new RecurringTaskFqcnVO(FailingRecurringTask::class);
        $payload = StrictDataObject::from([
            'should_fail' => true,
            'fail_message' => 'Test recurring failure',
        ]);

        $service->register($fqcn, $payload, $config);
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

    public function test_execute_with_interval_below_minimum_returns_invalid_argument(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--interval=2']
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

    // ==================== TESTS: Exécution en mode testing ====================

    public function test_execute_testing_mode_returns_success_when_no_tasks(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('🔬 Mode: TESTING', $response->output);
        $this->assertStringContainsString('🚀 Starting tasks watch loop...', $response->output);
        $this->assertStringContainsString('Duration: 1', $response->output);
        $this->assertStringContainsString('Interval: 3', $response->output);
        $this->assertStringContainsString('📊 Summary', $response->output);
        $this->assertStringContainsString('Total success:    0', $response->output);
        $this->assertStringContainsString('⏰ Duration reached.', $response->output);
    }

    public function test_execute_testing_mode_with_unique_only_flag(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--unique-only', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Options: --unique-only', $response->output);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertStringContainsString('Total success:    1', $response->output);
    }

    public function test_execute_testing_mode_with_recurring_only_flag(): void
    {
        $this->createRecurringTask('recurring-1', RecurringTaskStatus::PLAYING);

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--recurring-only', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Options: --recurring-only', $response->output);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertStringContainsString('Total success:    1', $response->output);
    }

    public function test_execute_testing_mode_with_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--unique-only', '--limit=3', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Options: --unique-only --limit=3', $response->output);
        $this->assertStringContainsString('✅ 3 tasks succeeded', $response->output);
        $this->assertStringContainsString('Total success:    3', $response->output);
    }

    public function test_execute_testing_mode_with_errors_returns_failure(): void
    {
        $this->createFailingUniqueTask();

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--duration=1', '--interval=3']
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
            ['--testing', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('❌ 1 tasks failed', $response->output);
        $this->assertStringContainsString('Total failures:   1', $response->output);
        $this->assertStringContainsString('Total errors:     1', $response->output);
    }

    public function test_execute_testing_mode_with_mixed_success_and_errors(): void
    {
        $this->createUniqueTask('unique-success');
        $this->createFailingUniqueTask();

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertStringContainsString('❌ 1 tasks failed', $response->output);
        $this->assertStringContainsString('Total success:    1', $response->output);
        $this->assertStringContainsString('Total failures:   1', $response->output);
        $this->assertStringContainsString('Total errors:     1', $response->output);
    }

    public function test_execute_testing_mode_with_interval_exactly_minimum_works(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--interval=3', '--unique-only', '--duration=1']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Interval: 3', $response->output);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertStringContainsString('Total success:    1', $response->output);
    }

    public function test_execute_testing_mode_handles_recurring_task_with_interval_not_reached(): void
    {
        $this->createRecurringTask(
            'recurring-skip',
            RecurringTaskStatus::PLAYING,
            Carbon::now()->subHours(2),
            Carbon::now()->subMinutes(30),
            3600
        );

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--recurring-only', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('✅ 0 tasks succeeded', $response->output);
        $this->assertStringContainsString('Total success:    0', $response->output);
        $this->assertStringContainsString('Total failures:   0', $response->output);
    }

    // ==================== TESTS: Sans mode testing ====================

    public function test_execute_without_testing_mode_works(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--duration=1', '--interval=3', '--unique-only']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('🚀 Starting tasks watch loop...', $response->output);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertStringContainsString('⏰ Duration reached. Stopping gracefully...', $response->output);
    }
}
