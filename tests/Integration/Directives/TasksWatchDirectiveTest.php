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
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
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

    private array $createdAliases = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DirectiveTestingService($this->app);

        $this->app->instance(DirectiveTestingService::class, $this->service);

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
    ): TaskAliasVO {
        $scheduledAt = $scheduledAt ?? Carbon::now()->subHours(2);
        $id = $id ?? $this->getUuidForAlias($alias);
        $aliasVO = $this->generateAliasFromName($alias, $id);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $aliasVO,
            'fqcn' => new UniqueTaskFqcnVO(TestUniqueTask::class),
            'payload' => StrictDataObject::from(['test' => 'unique']),
            'scheduled_at' => new Iso8601DateTimeVO($scheduledAt->format('Y-m-d\TH:i:sP')),
            'grace_period_seconds' => $gracePeriodSeconds,
            'status' => $status,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
        ]);

        $this->uniqueRepository->create($record);

        return $aliasVO;
    }

    private function createFailingUniqueTask(): TaskAliasVO
    {
        $alias = 'failing-task';
        $id = $this->getUuidForAlias($alias);
        $aliasVO = $this->generateAliasFromName($alias, $id);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $aliasVO,
            'fqcn' => new UniqueTaskFqcnVO(FailingTask::class),
            'payload' => StrictDataObject::from(['test' => 'failing']),
            'scheduled_at' => new Iso8601DateTimeVO(Carbon::now()->subHours(2)->format('Y-m-d\TH:i:sP')),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
            'attempts' => 2,
            'max_attempts' => 3,
        ]);

        $this->uniqueRepository->create($record);

        return $aliasVO;
    }

    private function createRecurringTask(
        string $alias,
        RecurringTaskStatus $status = RecurringTaskStatus::PLAYING,
        ?\DateTimeInterface $startAt = null,
        ?\DateTimeInterface $lastRunAt = null,
        int $intervalSeconds = 3600
    ): TaskAliasVO {
        $startAt = $startAt ?? Carbon::now()->subHours(2);
        $lastRunAt = $lastRunAt ?? Carbon::now()->subHours(2);

        $config = RecurringTaskConfigRecord::from([
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

        return $aliasVO;
    }

    private function createFailingRecurringTask(): TaskAliasVO
    {
        $frozenNow = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $config = RecurringTaskConfigRecord::from([
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

        return $service->register($fqcn, $payload, $config);
    }

    private function assertLineContains(string $haystack, string $needle): void
    {
        $lines = explode("\n", $haystack);
        foreach ($lines as $line) {
            $cleaned = preg_replace('/\s+/', ' ', trim($line));
            if (str_contains($cleaned, $needle)) {
                return;
            }
        }
        $this->fail(sprintf('Line containing "%s" not found', $needle));
    }

    private function assertLineContainsTwo(string $haystack, string $needle1, string $needle2): void
    {
        $lines = explode("\n", $haystack);
        foreach ($lines as $line) {
            $cleaned = preg_replace('/\s+/', ' ', trim($line));
            if (str_contains($cleaned, $needle1) && str_contains($cleaned, $needle2)) {
                return;
            }
        }
        $this->fail(sprintf('Line containing "%s" and "%s" not found', $needle1, $needle2));
    }

    /**
     * Assert that the output contains the options line.
     * If options are provided, check that each option is present.
     */
    private function assertOptionsDisplayed(string $output, array $expectedOptions = []): void
    {
        $lines = explode("\n", $output);
        $optionsLineFound = false;
        $optionsPart = '';

        foreach ($lines as $line) {
            $cleaned = preg_replace('/\s+/', ' ', trim($line));
            // Check for the options line with various possible formats
            if (str_contains($cleaned, 'Options:')) {
                $optionsLineFound = true;
                // Extract everything after "Options:"
                $parts = explode('Options:', $cleaned);
                $optionsPart = trim($parts[1] ?? '');
                break;
            }
        }

        // If we expect options, verify they are present
        if (! empty($expectedOptions)) {
            $this->assertTrue($optionsLineFound, 'Options line not found in output');
            foreach ($expectedOptions as $expected) {
                $this->assertStringContainsString($expected, $optionsPart, sprintf('Option "%s" not found in "%s"', $expected, $optionsPart));
            }
        }
        // If no expected options, just check that the output doesn't contain unexpected options
        // or that the options line is not present with weird content
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
        $this->assertStringContainsString('--parallel=', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $directive = $this->app->make(TasksWatchDirective::class);
        $description = $directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('interval', $description);
        $this->assertStringContainsString('seconds', $description);
        $this->assertStringContainsString('parallel', $description);
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

    public function test_execute_with_parallel_zero_returns_invalid_argument(): void
    {
        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--parallel=0', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Parallel workers must be at least 1', $response->output);
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

        $this->assertLineContainsTwo($response->output, 'Total success', '0');

        $this->assertStringContainsString('⏰ Duration reached.', $response->output);
    }

    public function test_execute_testing_mode_with_unique_only_flag(): void
    {
        $alias = $this->createUniqueTask('unique-1');

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--unique-only', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertOptionsDisplayed($response->output, ['--unique-only']);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);

        $this->assertLineContainsTwo($response->output, 'Total success', '1');
    }

    public function test_execute_testing_mode_with_recurring_only_flag(): void
    {
        $alias = $this->createRecurringTask('recurring-1', RecurringTaskStatus::PLAYING);

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--recurring-only', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertOptionsDisplayed($response->output, ['--recurring-only']);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);

        $this->assertLineContainsTwo($response->output, 'Total success', '1');
    }

    public function test_execute_testing_mode_with_limit(): void
    {
        $aliases = [];
        for ($i = 1; $i <= 5; $i++) {
            $aliases[] = $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--unique-only', '--limit=3', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertOptionsDisplayed($response->output, ['--unique-only', '--limit=3']);
        $this->assertStringContainsString('✅ 3 tasks succeeded', $response->output);

        $this->assertLineContainsTwo($response->output, 'Total success', '3');
    }

    public function test_execute_testing_mode_with_errors_returns_failure(): void
    {
        $alias = $this->createFailingUniqueTask();

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('❌ 1 tasks failed', $response->output);

        $this->assertLineContainsTwo($response->output, 'Total failures', '1');
        $this->assertLineContainsTwo($response->output, 'Total errors', '1');
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

        $this->assertLineContainsTwo($response->output, 'Total failures', '1');
        $this->assertLineContainsTwo($response->output, 'Total errors', '1');
    }

    public function test_execute_testing_mode_with_mixed_success_and_errors(): void
    {
        $alias1 = $this->createUniqueTask('unique-success');
        $alias2 = $this->createFailingUniqueTask();

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertStringContainsString('❌ 1 tasks failed', $response->output);

        $this->assertLineContainsTwo($response->output, 'Total success', '1');
        $this->assertLineContainsTwo($response->output, 'Total failures', '1');
        $this->assertLineContainsTwo($response->output, 'Total errors', '1');
    }

    // ==================== TESTS: Parallélisme ====================

    public function test_execute_testing_mode_with_parallel_workers_displays_parallel_mode(): void
    {
        $alias = $this->createUniqueTask('parallel-task-1');

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--parallel=3', '--unique-only', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertOptionsDisplayed($response->output, ['--unique-only', '--parallel=3']);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
        $this->assertLineContainsTwo($response->output, 'Total success', '1');
    }

    public function test_execute_testing_mode_with_parallel_workers_and_limit(): void
    {
        $aliases = [];
        for ($i = 1; $i <= 10; $i++) {
            $aliases[] = $this->createUniqueTask("parallel-task-{$i}");
        }

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--parallel=4', '--unique-only', '--limit=8', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertOptionsDisplayed($response->output, ['--unique-only', '--limit=8', '--parallel=4']);
        $this->assertStringContainsString('✅ 8 tasks succeeded', $response->output);
        $this->assertLineContainsTwo($response->output, 'Total success', '8');
    }

    public function test_execute_testing_mode_with_parallel_workers_and_errors(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->createUniqueTask("parallel-success-{$i}");
        }
        for ($i = 1; $i <= 2; $i++) {
            $this->createFailingUniqueTask();
        }

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--parallel=3', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('✅ 3 tasks succeeded', $response->output);
        $this->assertStringContainsString('❌ 2 tasks failed', $response->output);
        $this->assertLineContainsTwo($response->output, 'Total success', '3');
        $this->assertLineContainsTwo($response->output, 'Total failures', '2');
    }

    public function test_execute_testing_mode_with_parallel_workers_recurring_tasks(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createRecurringTask(
                "parallel-recurring-{$i}",
                RecurringTaskStatus::PLAYING,
                Carbon::now()->subHours(2),
                Carbon::now()->subHours(2),
                60
            );
        }

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--parallel=3', '--recurring-only', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertOptionsDisplayed($response->output, ['--recurring-only', '--parallel=3']);
        $this->assertStringContainsString('✅ 5 tasks succeeded', $response->output);
        $this->assertLineContainsTwo($response->output, 'Total success', '5');
    }

    public function test_execute_testing_mode_with_parallel_one_equals_sequential(): void
    {
        // Test avec parallel=1
        $alias1 = $this->createUniqueTask('sequential-task-1');

        $responseParallel1 = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--parallel=1', '--unique-only', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $responseParallel1->exit_code);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $responseParallel1->output);

        // Test sans parallel (séquentiel)
        $alias2 = $this->createUniqueTask('sequential-task-2');

        $responseSequential = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--unique-only', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $responseSequential->exit_code);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $responseSequential->output);
    }

    public function test_execute_testing_mode_with_parallel_and_verbose(): void
    {
        $alias = $this->createUniqueTask('verbose-parallel');

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--parallel=2', '--unique-only', '--verbose', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertOptionsDisplayed($response->output, ['--unique-only', '--verbose', '--parallel=2']);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
    }

    public function test_execute_testing_mode_with_parallel_and_both_task_types(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->createUniqueTask("parallel-mixed-unique-{$i}");
        }

        for ($i = 1; $i <= 3; $i++) {
            $this->createRecurringTask(
                "parallel-mixed-recurring-{$i}",
                RecurringTaskStatus::PLAYING,
                Carbon::now()->subHours(2),
                Carbon::now()->subHours(2),
                60
            );
        }

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--parallel=3', '--duration=1', '--interval=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertOptionsDisplayed($response->output, ['--parallel=3']);
        $this->assertStringContainsString('✅ 6 tasks succeeded', $response->output);
        $this->assertLineContainsTwo($response->output, 'Total success', '6');
    }

    public function test_execute_testing_mode_with_parallel_and_interval_exactly_minimum_works(): void
    {
        $alias = $this->createUniqueTask('parallel-min-interval');

        $response = $this->service->run(
            TasksWatchDirective::class,
            ['--testing', '--parallel=2', '--interval=3', '--unique-only', '--duration=1']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Interval: 3', $response->output);
        $this->assertOptionsDisplayed($response->output, ['--parallel=2', '--unique-only']);
        $this->assertStringContainsString('✅ 1 tasks succeeded', $response->output);
    }
}
