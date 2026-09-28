<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Directives\TasksSearchDirective;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
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

final class TasksSearchDirectiveTest extends IntegrationTestCase
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
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        string $fqcn = TestUniqueTask::class,
    ): TaskAliasVO {
        $id = Uuid::uuid4()->toString();
        $alias = new TaskAliasVO('unique@'.$id);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $alias,
            'fqcn' => new UniqueTaskFqcnVO($fqcn),
            'payload' => StrictDataObject::from(['test' => 'unique']),
            'scheduled_at' => new Iso8601DateTimeVO(Carbon::now()->subHours(2)->format('Y-m-d\TH:i:sP')),
            'grace_period_seconds' => new DurationVO(86400),
            'status' => $status,
            'attempts' => new CounterVO(0),
            'max_attempts' => new MaxFailedAttemptsVO(3),
        ]);

        $this->uniqueRepository->create($record);

        return $alias;
    }

    private function createRecurringTask(
        RecurringTaskStatus $status = RecurringTaskStatus::PLAYING,
        string $fqcn = TestRecurringTask::class,
    ): TaskAliasVO {
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

        return $aliasVO;
    }

    // ==================== TESTS: METADATA ====================

    public function test_get_signature_returns_correct_string(): void
    {
        $signature = $this->app->make(TasksSearchDirective::class)->getSignature();

        $this->assertStringContainsString('tasks:search', $signature);
        $this->assertStringContainsString('aliases*', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $description = $this->app->make(TasksSearchDirective::class)->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $aliases = $this->app->make(TasksSearchDirective::class)->getAliases();

        $this->assertTrue($aliases->contains('tasks:find'));
        $this->assertTrue($aliases->contains('t:find'));
        $this->assertSame(2, $aliases->count());
    }

    // ==================== TESTS: VALIDATION ====================

    public function test_execute_without_aliases_returns_error(): void
    {
        $response = $this->service->runDirective(TasksSearchDirective::class, []);

        $this->assertNotSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('At least one alias is required', $response->output);
    }

    // ==================== TESTS: UNIQUE ====================

    public function test_execute_finds_unique_task_by_alias(): void
    {
        $alias = $this->createUniqueTask();

        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['['.$alias->getValue().']'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('unique', $response->output);
        $this->assertStringContainsString($alias->getValue(), $response->output);
        $this->assertStringContainsString('TestUniqueTask', $response->output);
    }

    public function test_execute_finds_unique_task_with_failed_status(): void
    {
        $alias = $this->createUniqueTask(
            UniqueTaskStatus::FAILED,
            FailingTask::class,
        );

        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['['.$alias->getValue().']'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('failed', $response->output);
        $this->assertStringContainsString('FailingTask', $response->output);
    }

    // ==================== TESTS: RECURRING ====================

    public function test_execute_finds_recurring_task_by_alias(): void
    {
        $alias = $this->createRecurringTask();

        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['['.$alias->getValue().']'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('recurring', $response->output);
        $this->assertStringContainsString($alias->getValue(), $response->output);
        $this->assertStringContainsString('TestRecurringTask', $response->output);
    }

    public function test_execute_finds_recurring_task_with_paused_status(): void
    {
        $alias = $this->createRecurringTask(RecurringTaskStatus::PAUSED);

        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['['.$alias->getValue().']'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('paused', $response->output);
    }

    // ==================== TESTS: MULTIPLE ALIASES ====================

    public function test_execute_finds_multiple_tasks(): void
    {
        $uniqueAlias = $this->createUniqueTask();
        $recurringAlias = $this->createRecurringTask();

        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['['.$uniqueAlias->getValue().', '.$recurringAlias->getValue().']'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('unique', $response->output);
        $this->assertStringContainsString('recurring', $response->output);
    }

    public function test_execute_finds_multiple_unique_tasks(): void
    {
        $alias1 = $this->createUniqueTask();
        $alias2 = $this->createUniqueTask(UniqueTaskStatus::FAILED, FailingTask::class);

        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['['.$alias1->getValue().', '.$alias2->getValue().']'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('TestUniqueTask', $response->output);
        $this->assertStringContainsString('FailingTask', $response->output);
    }

    // ==================== TESTS: NOT FOUND ====================

    public function test_execute_with_single_unknown_alias_returns_failure(): void
    {
        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['[unique@00000000-0000-0000-0000-000000000000]'],
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('No matching task found', $response->output);
        $this->assertStringContainsString(
            'Task not found: unique@00000000-0000-0000-0000-000000000000',
            $response->output,
        );
    }

    public function test_execute_with_partial_match_returns_failure_but_shows_found(): void
    {
        $found = $this->createUniqueTask();

        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['['.$found->getValue().', unique@00000000-0000-0000-0000-000000000000]'],
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString($found->getValue(), $response->output);
        $this->assertStringContainsString(
            'Task not found: unique@00000000-0000-0000-0000-000000000000',
            $response->output,
        );
    }

    public function test_execute_with_multiple_unknown_aliases_warns_for_each(): void
    {
        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['[unique@00000000-0000-0000-0000-000000000000, recurring@11111111-1111-1111-1111-111111111111]'],
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString(
            'Task not found: unique@00000000-0000-0000-0000-000000000000',
            $response->output,
        );
        $this->assertStringContainsString(
            'Task not found: recurring@11111111-1111-1111-1111-111111111111',
            $response->output,
        );
    }

    // ==================== TESTS: ALIASES ====================

    public function test_alias_tasks_find_works(): void
    {
        $alias = $this->createUniqueTask();

        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['['.$alias->getValue().']'],
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    // ==================== TESTS: OUTPUT FORMAT ====================

    public function test_output_contains_table_headers(): void
    {
        $alias = $this->createUniqueTask();

        $response = $this->service->runDirective(
            TasksSearchDirective::class,
            ['['.$alias->getValue().']'],
        );

        $this->assertStringContainsString('Kind', $response->output);
        $this->assertStringContainsString('Alias', $response->output);
        $this->assertStringContainsString('FQCN', $response->output);
        $this->assertStringContainsString('Status', $response->output);
    }
}
