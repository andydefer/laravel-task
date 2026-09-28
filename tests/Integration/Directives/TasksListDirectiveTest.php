<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Directives\TasksListDirective;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\HelloUniqueTask;
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

final class TasksListDirectiveTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private DirectiveTestingService $service;

    private UniqueTaskRepository $uniqueRepository;

    private RecurringTaskRepositoryInterface $recurringRepository;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 6, 23, 12, 0, 0));

        $this->service = new DirectiveTestingService($this->app);

        $this->uniqueRepository = new UniqueTaskRepository(
            new TaskExecutionDebugRepository,
            $this->app->make(LoggerInterface::class),
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

    private function createUniqueTask(
        string $alias,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $attempts = 0,
        string $fqcn = TestUniqueTask::class,
    ): void {
        $id = Uuid::uuid4()->toString();
        $scheduledAt = $scheduledAt ?? Carbon::now()->subHours(2);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => new TaskAliasVO('unique@'.$id),
            'fqcn' => new UniqueTaskFqcnVO($fqcn),
            'payload' => StrictDataObject::from(['test' => 'unique']),
            'scheduled_at' => new Iso8601DateTimeVO($scheduledAt->format('Y-m-d\TH:i:sP')),
            'grace_period_seconds' => new DurationVO(86400),
            'status' => $status,
            'attempts' => new CounterVO($attempts),
            'max_attempts' => new MaxFailedAttemptsVO(3),
        ]);

        $this->uniqueRepository->create($record);
    }

    private function createRecurringTask(
        RecurringTaskStatus $status = RecurringTaskStatus::PLAYING,
        string $fqcn = TestRecurringTask::class,
    ): void {
        $config = RecurringTaskConfigRecord::from([
            'type' => TaskType::RECURRING->value,
            'description' => 'Test recurring',
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2),
            'end_at' => Carbon::now()->addDays(7),
            'max_attempts' => 3,
        ]);

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $aliasVO = $service->register(
            new RecurringTaskFqcnVO($fqcn),
            StrictDataObject::from(['test' => 'recurring']),
            $config,
        );

        $task = $this->recurringRepository->findByAlias($aliasVO);
        if ($task !== null) {
            $this->recurringRepository->updateRaw(
                $task->getId()->getValue(),
                ['status' => $status->value],
            );
        }
    }

    // ==================== TESTS: METADATA ====================

    public function test_get_signature_returns_correct_string(): void
    {
        $signature = $this->app->make(TasksListDirective::class)->getSignature();

        $this->assertStringContainsString('tasks:list', $signature);
        $this->assertStringContainsString('limit=50', $signature);
        $this->assertStringContainsString('fqcns*', $signature);
        $this->assertStringContainsString('kinds*>[unique,recurring]', $signature);
        $this->assertStringContainsString('unique_statuses*>[pending,completed,in_progress,failed,canceled]', $signature);
        $this->assertStringContainsString('recurring_statuses*>[waiting,playing,paused,finished,canceled]', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $description = $this->app->make(TasksListDirective::class)->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $aliases = $this->app->make(TasksListDirective::class)->getAliases();

        $this->assertTrue($aliases->contains('tasks:ls'));
        $this->assertTrue($aliases->contains('t:ls'));
        $this->assertSame(2, $aliases->count());
    }

    // ==================== TESTS: EMPTY STATE ====================
    public function test_execute_with_no_tasks_returns_success(): void
    {
        $response = $this->service->runDirective(TasksListDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('No data to display', $response->output);
    }
    // ==================== TESTS: DEFAULT LISTING ====================

    public function test_execute_lists_unique_and_recurring_by_default(): void
    {
        $this->createUniqueTask('u-1');
        $this->createRecurringTask();

        $response = $this->service->runDirective(TasksListDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('unique', $response->output);
        $this->assertStringContainsString('recurring', $response->output);
    }

    // ==================== TESTS: KIND FILTER ====================

    public function test_execute_with_unique_kind_only(): void
    {
        $this->createUniqueTask('u-1');
        $this->createRecurringTask();

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['50', '[]', '[unique]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('unique', $response->output);
        $this->assertStringNotContainsString('recurring', $response->output);
    }

    public function test_execute_with_recurring_kind_only(): void
    {
        $this->createUniqueTask('u-1');
        $this->createRecurringTask();

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['50', '[]', '[recurring]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('recurring', $response->output);
        $this->assertStringNotContainsString('unique', $response->output);
    }

    public function test_execute_with_both_kinds(): void
    {
        $this->createUniqueTask('u-1');
        $this->createRecurringTask();

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['50', '[]', '[unique,recurring]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('unique', $response->output);
        $this->assertStringContainsString('recurring', $response->output);
    }

    // ==================== TESTS: STATUS FILTERS ====================

    public function test_execute_with_unique_status_filter(): void
    {
        $this->createUniqueTask('u-pending', UniqueTaskStatus::PENDING);
        $this->createUniqueTask('u-failed', UniqueTaskStatus::FAILED);

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['50', '[]', '[unique]', '[failed]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('failed', $response->output);
    }

    public function test_execute_with_recurring_status_filter(): void
    {
        $this->createRecurringTask(RecurringTaskStatus::PLAYING);

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['50', '[]', '[recurring]', '[]', '[playing]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('playing', $response->output);
    }

    public function test_execute_with_invalid_unique_status_returns_error(): void
    {
        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['50', '_', '[unique]', '[unknown]'],
        );

        $this->assertNotSame(ExitCode::SUCCESS, $response->exit_code);
    }

    // ==================== TESTS: LIMIT ====================

    public function test_execute_with_custom_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("u-{$i}");
        }

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['2', '[]', '[unique]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_invalid_limit_returns_error(): void
    {
        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['00'],
        );

        $this->assertNotSame(ExitCode::SUCCESS, $response->exit_code);
    }

    // ==================== TESTS: FQCN FILTER ====================

    public function test_execute_with_single_fqcn_filter_dot_notation(): void
    {
        $this->createUniqueTask('u-1', UniqueTaskStatus::PENDING, null, 0, TestUniqueTask::class);
        $this->createUniqueTask('u-2', UniqueTaskStatus::PENDING, null, 0, FailingTask::class);

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['50', '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask]', '[unique]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('TestUniqueTask', $response->output);
        $this->assertStringNotContainsString('FailingTask', $response->output);
    }

    public function test_execute_with_multiple_fqcn_filters(): void
    {
        $this->createUniqueTask('u-1', UniqueTaskStatus::PENDING, null, 0, TestUniqueTask::class);
        $this->createUniqueTask('u-2', UniqueTaskStatus::PENDING, null, 0, HelloUniqueTask::class);
        $this->createUniqueTask('u-3', UniqueTaskStatus::PENDING, null, 0, FailingTask::class);

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['50', '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask, AndyDefer.Task.Tests.Fixtures.Tasks.HelloUniqueTask]', '[unique]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('TestUniqueTask', $response->output);
        $this->assertStringContainsString('HelloUniqueTask', $response->output);
        $this->assertStringNotContainsString('FailingTask', $response->output);
    }

    public function test_execute_with_empty_fqcn_filter_lists_all(): void
    {
        $this->createUniqueTask('u-1');
        $this->createUniqueTask('u-2', UniqueTaskStatus::FAILED);

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['50', '[]', '[unique]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('unique', $response->output);
    }

    // ==================== TESTS: COMBINED OPTIONS ====================

    public function test_execute_with_kind_status_and_fqcn_combined(): void
    {
        $this->createUniqueTask('u-1', UniqueTaskStatus::PENDING, null, 0, TestUniqueTask::class);
        $this->createUniqueTask('u-2', UniqueTaskStatus::COMPLETED, null, 0, TestUniqueTask::class);

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['50', '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask]', '[unique]', '[pending]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('pending', $response->output);
    }

    public function test_execute_with_limit_kind_status_and_fqcn(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("u-{$i}", UniqueTaskStatus::PENDING, null, 0, TestUniqueTask::class);
        }

        $response = $this->service->runDirective(
            TasksListDirective::class,
            ['3', '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask]', '[unique]', '[pending]'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }
}
