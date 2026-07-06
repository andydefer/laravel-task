<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Processors;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Loggers\RecurringTaskLoggerInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskExecutionDebugRepositoryInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Processors\RecurringTaskProcessor;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Runners\RecurringTaskRunner;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTaskForProcessor;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

final class RecurringTaskProcessorTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private RecurringTaskProcessor $processor;

    private RecurringTaskRepositoryInterface $repository;

    private TaskExecutionDebugRepositoryInterface $debugRepository;

    private RecurringTaskValidator $validator;

    private RecurringTaskRunner $runner;

    private RecurringTaskLoggerInterface $logger;

    private HydrationService $hydration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debugRepository = new TaskExecutionDebugRepository;

        // ✅ Récupérer les services via $this->app
        $this->logger = $this->app->make(RecurringTaskLoggerInterface::class);
        $this->hydration = $this->app->make(HydrationService::class);
        $logger = $this->app->make(LoggerInterface::class);

        $this->repository = new RecurringTaskRepository(
            debugRepository: $this->debugRepository,
            logger: $logger
        );

        $this->validator = new RecurringTaskValidator;

        $this->runner = new RecurringTaskRunner(
            validator: $this->validator,
            logger: $this->logger,  // ✅ RecurringTaskLoggerInterface
            hydration: $this->hydration,
            app: $this->app,
            repository: $this->repository,
        );

        $this->processor = new RecurringTaskProcessor(
            repository: $this->repository,
            runner: $this->runner,
            validator: $this->validator,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Carbon::setTestNow(null);
    }

    // ==================== HELPERS ====================

    private function generateUuid(): string
    {
        return Uuid::uuid4()->toString();
    }

    private function createAliasVO(?string $uuid = null): TaskAliasVO
    {
        $uuid = $uuid ?? $this->generateUuid();

        return new TaskAliasVO(
            type: ('recurring'),
            uuid: $uuid
        );
    }

    private function createFqcnVO(string $fqcn = TestRecurringTask::class): TaskFqcnVO
    {
        return new TaskFqcnVO($fqcn);
    }

    private function formatDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }

    private function createTask(
        ?string $alias = null,
        RecurringTaskStatus $status = RecurringTaskStatus::WAITING,
        ?Carbon $startAt = null,
        ?Carbon $endAt = null,
        int $intervalSeconds = 3600,
        ?Carbon $lastRunAt = null
    ): void {
        $alias = $alias ?? $this->generateUuid();
        $startAt = $startAt ?? Carbon::now()->subHours(2);
        $endAt = $endAt ?? Carbon::now()->addDays(7);
        $id = $this->generateUuid();

        $record = RecurringTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $this->createAliasVO($alias),
            'fqcn' => $this->createFqcnVO(),
            'payload' => ['test' => 'recurring'],
            'interval_seconds' => new DurationVO($intervalSeconds),
            'start_at' => new Iso8601DateTimeVO($this->formatDate($startAt)),
            'end_at' => new Iso8601DateTimeVO($this->formatDate($endAt)),
            'status' => $status,
            'last_run_at' => $lastRunAt ? new Iso8601DateTimeVO($this->formatDate($lastRunAt)) : null,
            'failed_attempts' => new CounterVO(0),
            'max_failed_attempts' => new MaxFailedAttemptsVO(3),
        ]);

        $this->repository->create($record);
    }

    private function createFailingTask(
        ?string $alias = null,
        RecurringTaskStatus $status = RecurringTaskStatus::WAITING,
        ?Carbon $startAt = null,
        ?Carbon $endAt = null,
        int $intervalSeconds = 3600
    ): void {
        $alias = $alias ?? $this->generateUuid();
        $startAt = $startAt ?? Carbon::now()->subHours(2);
        $endAt = $endAt ?? Carbon::now()->addDays(7);
        $id = $this->generateUuid();

        $record = RecurringTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $this->createAliasVO($alias),
            'fqcn' => $this->createFqcnVO(FailingRecurringTaskForProcessor::class),
            'payload' => ['should_fail' => true],
            'interval_seconds' => new DurationVO($intervalSeconds),
            'start_at' => new Iso8601DateTimeVO($this->formatDate($startAt)),
            'end_at' => new Iso8601DateTimeVO($this->formatDate($endAt)),
            'status' => $status,
            'last_run_at' => null,
            'failed_attempts' => new CounterVO(0),
            'max_failed_attempts' => new MaxFailedAttemptsVO(3),
        ]);

        $this->repository->create($record);
    }

    private function getTaskByAlias(string $alias): ?RecurringTask
    {
        return $this->repository->findByAlias($this->createAliasVO($alias));
    }

    // ==================== TESTS ====================

    public function test_process_starts_waiting_task_when_start_at_reached(): void
    {
        $now = Carbon::now();
        $alias = $this->generateUuid();

        $this->createTask(
            $alias,
            RecurringTaskStatus::WAITING,
            $now->copy()->subHours(2),
            $now->copy()->addDays(7)
        );

        $result = $this->processor->process(new LimitVO);

        $this->assertEquals(1, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $task = $this->getTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
    }

    public function test_process_does_not_start_waiting_task_when_start_at_future(): void
    {
        $now = Carbon::now();
        $alias = $this->generateUuid();

        $this->createTask(
            $alias,
            RecurringTaskStatus::WAITING,
            $now->copy()->addHours(2),
            $now->copy()->addDays(7)
        );

        $result = $this->processor->process(new LimitVO);

        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $task = $this->getTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::WAITING, $task->getStatus());
    }

    public function test_process_executes_playing_task_when_interval_reached(): void
    {
        $now = Carbon::now();
        $alias = $this->generateUuid();

        $this->createTask(
            $alias,
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(1),
            $now->copy()->addDays(7),
            3600,
            $now->copy()->subHours(2)
        );

        $result = $this->processor->process(new LimitVO);

        $this->assertEquals(1, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $task = $this->getTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
        $this->assertNotNull($task->getLastRunAt());
    }

    public function test_process_does_not_execute_playing_task_when_interval_not_reached(): void
    {
        $now = Carbon::now();
        $alias = $this->generateUuid();

        $this->createTask(
            $alias,
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(1),
            $now->copy()->addDays(7),
            3600,
            $now->copy()->subMinutes(30)
        );

        $result = $this->processor->process(new LimitVO);

        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $task = $this->getTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
        $this->assertNotNull($task->getLastRunAt());
        $lastRun = $task->getLastRunAt()->toCarbon();
        $this->assertEquals(
            $now->copy()->subMinutes(30)->format('Y-m-d H:i'),
            $lastRun->format('Y-m-d H:i')
        );
    }

    public function test_process_finishes_task_when_end_at_reached(): void
    {
        $now = Carbon::now();
        $alias = $this->generateUuid();

        $this->createTask(
            $alias,
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(7),
            $now->copy()->subHours(1)
        );

        $result = $this->processor->process(new LimitVO);

        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(1, $result->finished->getValue());

        $task = $this->getTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::FINISHED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    public function test_process_finishes_waiting_task_when_end_at_reached(): void
    {
        $now = Carbon::now();
        $alias = $this->generateUuid();

        $this->createTask(
            $alias,
            RecurringTaskStatus::WAITING,
            $now->copy()->subDays(7),
            $now->copy()->subHours(1)
        );

        $result = $this->processor->process(new LimitVO);

        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(1, $result->finished->getValue());

        $task = $this->getTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::FINISHED, $task->getStatus());
    }

    public function test_process_handles_task_failure(): void
    {
        $now = Carbon::now();
        $alias = $this->generateUuid();

        $this->createFailingTask(
            $alias,
            RecurringTaskStatus::WAITING,
            $now->copy()->subHours(2),
            $now->copy()->addDays(7)
        );

        $result = $this->processor->process(new LimitVO);

        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(1, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $task = $this->getTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
        $this->assertNotNull($task->getLastRunAt());
    }

    public function test_process_respects_limit(): void
    {
        $now = Carbon::now();
        $aliases = [];

        for ($i = 1; $i <= 3; $i++) {
            $alias = $this->generateUuid();
            $aliases[] = $alias;
            $this->createTask(
                $alias,
                RecurringTaskStatus::WAITING,
                $now->copy()->subHours(2),
                $now->copy()->addDays(7)
            );
        }

        $result = $this->processor->process(new LimitVO(2));

        $this->assertEquals(2, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $executedTasks = 0;
        foreach ($aliases as $alias) {
            $task = $this->getTaskByAlias($alias);
            if ($task !== null && $task->getLastRunAt() !== null) {
                $executedTasks++;
            }
        }
        $this->assertEquals(2, $executedTasks);

        foreach ($aliases as $alias) {
            $task = $this->getTaskByAlias($alias);
            $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
        }
    }

    public function test_process_handles_multiple_scenarios(): void
    {
        $now = Carbon::now();

        $alias1 = $this->generateUuid();
        $alias2 = $this->generateUuid();
        $alias3 = $this->generateUuid();

        $this->createTask(
            $alias1,
            RecurringTaskStatus::WAITING,
            $now->copy()->subHours(2),
            $now->copy()->addDays(7)
        );

        $this->createTask(
            $alias2,
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(1),
            $now->copy()->addDays(7),
            3600,
            $now->copy()->subHours(2)
        );

        $this->createTask(
            $alias3,
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(7),
            $now->copy()->subHours(1)
        );

        $result = $this->processor->process(new LimitVO);

        $this->assertEquals(2, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(1, $result->finished->getValue());

        $task1 = $this->getTaskByAlias($alias1);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task1->getStatus());

        $task2 = $this->getTaskByAlias($alias2);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task2->getStatus());

        $task3 = $this->getTaskByAlias($alias3);
        $this->assertEquals(RecurringTaskStatus::FINISHED, $task3->getStatus());
    }

    public function test_process_records_errors_in_result(): void
    {
        $now = Carbon::now();

        $alias1 = $this->generateUuid();
        $alias2 = $this->generateUuid();

        $this->createFailingTask(
            $alias1,
            RecurringTaskStatus::WAITING,
            $now->copy()->subHours(2),
            $now->copy()->addDays(7)
        );

        $this->createTask(
            $alias2,
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(7),
            $now->copy()->subHours(1)
        );

        $result = $this->processor->process(new LimitVO);

        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(1, $result->failed->getValue());
        $this->assertEquals(1, $result->finished->getValue());
        $this->assertGreaterThan(0, $result->errors->count());
    }
}
