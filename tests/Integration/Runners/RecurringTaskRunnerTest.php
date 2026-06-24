<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Runners;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Loggers\RecurringTaskLogger;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Runners\RecurringTaskRunner;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\RecurringTaskValidator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\App;

final class RecurringTaskRunnerTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private RecurringTaskRunner $runner;

    private RecurringTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    private RecurringTaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new RecurringTaskRepository($this->debugRepository);
        $this->validator = new RecurringTaskValidator;

        $logger = new RecurringTaskLogger(
            logger: App::make(LoggerInterface::class),
            hydration: App::make(HydrationService::class),
        );

        $this->runner = new RecurringTaskRunner(
            validator: $this->validator,
            logger: $logger,
            hydration: App::make(HydrationService::class),
            app: App::getFacadeApplication(),
            repository: $this->repository,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function createTaskRecord(
        string $alias,
        RecurringTaskStatus $status = RecurringTaskStatus::PLAYING,
        ?string $fqcn = null,
        ?\DateTimeInterface $lastRunAt = null
    ): RecurringTaskRecord {
        $fqcn = $fqcn ?? TestRecurringTask::class;
        $now = now();

        $record = RecurringTaskRecord::from([
            'alias' => $alias,
            'fqcn' => $fqcn,
            'payload' => ['test' => 'runner'],
            'interval_seconds' => 3600,
            'start_at' => $now->subDay()->toIso8601String(),
            'end_at' => $now->addDays(7)->toIso8601String(),
            'status' => $status,
            'last_run_at' => $lastRunAt ? $lastRunAt->format('Y-m-d\TH:i:sP') : null,
        ]);

        $this->repository->create($record);

        return $record;
    }

    // ==================== TESTS ====================

    public function test_run_successfully_executes_task(): void
    {
        $record = $this->createTaskRecord('test-run-success');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertGreaterThanOrEqual(0, $result->execution_time);

        $task = $this->repository->findByAlias('test-run-success');
        $this->assertNotNull($task);
        $this->assertNotNull($task->getLastRunAt());

        $debugs = $this->debugRepository->findByTask('recurring', 'test-run-success');
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Recurring task executed successfully', $debugData->info);
    }

    public function test_run_returns_failure_when_task_not_in_playing_status(): void
    {
        $record = $this->createTaskRecord('test-run-waiting', RecurringTaskStatus::WAITING);

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals('test-run-waiting', $result->error->alias);
        $this->assertStringContainsString('Validation failed', $result->error->error);

        $task = $this->repository->findByAlias('test-run-waiting');
        $this->assertNotNull($task);
        $this->assertNull($task->getLastRunAt());
    }

    public function test_run_returns_failure_when_task_expired(): void
    {
        $now = now();

        $record = RecurringTaskRecord::from([
            'alias' => 'test-run-expired',
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'runner'],
            'interval_seconds' => 3600,
            'start_at' => $now->subDays(7)->toIso8601String(),
            'end_at' => $now->subDay()->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->repository->create($record);

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Validation failed', $result->error->error);
    }

    public function test_run_skips_execution_when_interval_not_reached(): void
    {
        $now = now();

        $record = $this->createTaskRecord(
            'test-run-skip',
            RecurringTaskStatus::PLAYING,
            TestRecurringTask::class,
            $now->copy()->subMinutes(30)
        );

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertEquals(0.0, $result->execution_time);

        $task = $this->repository->findByAlias('test-run-skip');
        $this->assertNotNull($task);
        $this->assertEquals(
            $now->copy()->subMinutes(30)->format('Y-m-d H:i'),
            $task->getLastRunAt()->toDateTime()->format('Y-m-d H:i')
        );

        $debugs = $this->debugRepository->findByTask('recurring', 'test-run-skip');
        $this->assertCount(0, $debugs);
    }

    public function test_run_handles_task_exception(): void
    {
        $now = now();

        $record = RecurringTaskRecord::from([
            'alias' => 'test-run-failing',
            'fqcn' => FailingRecurringTask::class,
            'payload' => ['should_fail' => true, 'fail_message' => 'Test failure'],
            'interval_seconds' => 3600,
            'start_at' => $now->subDay()->toIso8601String(),
            'end_at' => $now->addDays(7)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->repository->create($record);

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals('Test failure', $result->error->error);

        $task = $this->repository->findByAlias('test-run-failing');
        $this->assertNotNull($task);
        $this->assertNotNull($task->getLastRunAt());

        $debugs = $this->debugRepository->findByTask('recurring', 'test-run-failing');
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('failed', $debugData->status);
        $this->assertEquals('Test failure', $debugData->info);
    }

    public function test_run_returns_execution_time(): void
    {
        $record = $this->createTaskRecord('test-run-time');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertIsFloat($result->execution_time);
        $this->assertGreaterThanOrEqual(0, $result->execution_time);
    }

    public function test_run_logs_start_and_success(): void
    {
        $record = $this->createTaskRecord('test-run-logs');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $debugs = $this->debugRepository->findByTask('recurring', 'test-run-logs');
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Recurring task executed successfully', $debugData->info);
        $this->assertNotNull($debugData->acted_at);
    }

    public function test_run_preserves_task_in_playing_status(): void
    {
        $record = $this->createTaskRecord('test-run-preserve');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $task = $this->repository->findByAlias('test-run-preserve');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
    }

    public function test_run_does_not_change_other_task_data(): void
    {
        $alias = 'test-run-data';
        $now = now();

        $record = RecurringTaskRecord::from([
            'alias' => $alias,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'runner', 'data' => 'should_persist'],
            'interval_seconds' => 7200,
            'start_at' => $now->subDays(2)->toIso8601String(),
            'end_at' => $now->addDays(14)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->repository->create($record);

        $result = $this->runner->run($record);
        $this->assertTrue($result->success);

        $task = $this->repository->findByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(TestRecurringTask::class, $task->getFqcn());
        $this->assertEquals(7200, $task->getIntervalSeconds()->value);
        $this->assertEquals('should_persist', $task->getPayload()->toArray()['data']);
    }

    public function test_run_handles_null_last_run_at(): void
    {
        $record = $this->createTaskRecord(
            'test-run-first',
            RecurringTaskStatus::PLAYING,
            null,
            null
        );

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $task = $this->repository->findByAlias('test-run-first');
        $this->assertNotNull($task);
        $this->assertNotNull($task->getLastRunAt());
    }
}
