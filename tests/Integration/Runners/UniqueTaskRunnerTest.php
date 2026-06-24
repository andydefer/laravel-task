<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Runners;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Loggers\UniqueTaskLogger;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Runners\UniqueTaskRunner;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTaskWithCustomConfig;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\UniqueTaskValidator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\App;
use Ramsey\Uuid\Uuid;

final class UniqueTaskRunnerTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private UniqueTaskRunner $runner;

    private UniqueTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    private UniqueTaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new UniqueTaskRepository($this->debugRepository);
        $this->validator = new UniqueTaskValidator;

        $logger = new UniqueTaskLogger(
            logger: App::make(LoggerInterface::class),
            hydration: App::make(HydrationService::class),
        );

        $this->runner = new UniqueTaskRunner(
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
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3,
        ?string $fqcn = null
    ): UniqueTaskRecord {
        $scheduledAt = $scheduledAt ?? now();
        $id = $id ?? (string) Uuid::uuid4();
        $fqcn = $fqcn ?? TestUniqueTask::class;

        $record = UniqueTaskRecord::from([
            'id' => $id,
            'alias' => $alias,
            'fqcn' => $fqcn,
            'payload' => ['test' => 'runner'],
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:sP'),
            'grace_period_seconds' => $gracePeriodSeconds,
            'status' => $status,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
        ]);

        $this->repository->create($record);

        return $record;
    }

    private function findTaskById(string $id): ?UniqueTask
    {
        return $this->repository->findById($id);
    }

    // ==================== TESTS ====================

    public function test_run_successfully_executes_task(): void
    {
        $record = $this->createTaskRecord('test-run-success');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertGreaterThanOrEqual(0, $result->execution_time);

        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());

        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Task executed successfully', $debugData->info);
    }

    public function test_run_returns_failure_when_task_not_in_pending_status(): void
    {
        $record = $this->createTaskRecord(
            'test-run-completed',
            null,
            UniqueTaskStatus::COMPLETED
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->alias->value, $result->error->alias);
        $this->assertStringContainsString('Validation failed', $result->error->error);
        $this->assertStringContainsString('Task is not in PENDING state', $result->error->error);
    }

    public function test_run_returns_failure_when_scheduled_at_in_future(): void
    {
        $record = $this->createTaskRecord(
            'test-run-future',
            null,
            UniqueTaskStatus::PENDING,
            now()->addHours(2)
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->alias->value, $result->error->alias);
        $this->assertStringContainsString('Validation failed', $result->error->error);
        $this->assertStringContainsString('Task is not ready to run', $result->error->error);
    }

    public function test_run_returns_failure_when_max_attempts_reached(): void
    {
        $record = $this->createTaskRecord(
            'test-run-max-attempts',
            null,
            UniqueTaskStatus::PENDING,
            now()->subHours(2),
            86400,
            3,
            3
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->alias->value, $result->error->alias);
        $this->assertStringContainsString('Validation failed', $result->error->error);
        $this->assertStringContainsString('Maximum attempts reached', $result->error->error);
    }

    public function test_run_returns_failure_when_task_expired(): void
    {
        $record = $this->createTaskRecord(
            'test-run-expired',
            null,
            UniqueTaskStatus::PENDING,
            now()->subDays(2),
            3600
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->alias->value, $result->error->alias);
        $this->assertStringContainsString('Validation failed', $result->error->error);
        $this->assertStringContainsString('Task has expired', $result->error->error);
    }

    public function test_run_handles_task_exception(): void
    {
        $record = $this->createTaskRecord(
            'test-run-failing',
            null,
            UniqueTaskStatus::PENDING,
            now()->subHours(2),
            86400,
            0,
            3,
            FailingTask::class
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals('Test exception', $result->error->error);

        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());

        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('failed', $debugData->status);
        $this->assertEquals('Test exception', $debugData->info);
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

        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Task executed successfully', $debugData->info);
        $this->assertNotNull($debugData->acted_at);
    }

    public function test_run_sets_completed_status_on_success(): void
    {
        $record = $this->createTaskRecord('test-run-completed-status');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    public function test_run_sets_failed_status_on_failure(): void
    {
        $record = $this->createTaskRecord(
            'test-run-failed-status',
            null,
            UniqueTaskStatus::PENDING,
            now()->subHours(2),
            86400,
            0,
            3,
            FailingTask::class
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);

        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    public function test_run_does_not_change_other_task_data(): void
    {
        $alias = 'test-run-data';
        $id = (string) Uuid::uuid4();

        $record = UniqueTaskRecord::from([
            'id' => $id,
            'alias' => $alias,
            'fqcn' => TestUniqueTask::class,
            'payload' => ['test' => 'runner', 'data' => 'should_persist'],
            'scheduled_at' => now()->subHours(2)->format('Y-m-d\TH:i:sP'),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $this->repository->create($record);

        $result = $this->runner->run($record);
        $this->assertTrue($result->success);

        $task = $this->findTaskById($id);
        $this->assertNotNull($task);
        $this->assertEquals(TestUniqueTask::class, $task->getFqcn());
        $this->assertEquals('should_persist', $task->getPayload()->toArray()['data']);
        $this->assertEquals(86400, $task->getGracePeriodSeconds());
    }

    public function test_run_with_custom_task_class(): void
    {
        $record = $this->createTaskRecord(
            'test-run-custom',
            null,
            UniqueTaskStatus::PENDING,
            now()->subHours(2),
            86400,
            0,
            3,
            TestUniqueTaskWithCustomConfig::class
        );

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);

        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
    }

    public function test_run_handles_null_payload(): void
    {
        $id = (string) Uuid::uuid4();

        $record = UniqueTaskRecord::from([
            'id' => $id,
            'alias' => 'test-null-payload',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => now()->subHours(2)->format('Y-m-d\TH:i:sP'),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $this->repository->create($record);

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $task = $this->findTaskById($id);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
    }

    public function test_run_adds_debug_on_success(): void
    {
        $record = $this->createTaskRecord('test-run-debug-success');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(1, $debugs);

        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Task executed successfully', $debugData->info);
        $this->assertNotNull($debugData->acted_at);
    }

    public function test_run_adds_debug_on_failure(): void
    {
        $record = $this->createTaskRecord(
            'test-run-debug-failure',
            null,
            UniqueTaskStatus::PENDING,
            now()->subHours(2),
            86400,
            0,
            3,
            FailingTask::class
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);

        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(1, $debugs);

        $debugData = $debugs->first()->getData();
        $this->assertEquals('failed', $debugData->status);
        $this->assertEquals('Test exception', $debugData->info);
        $this->assertNotNull($debugData->acted_at);
    }

    public function test_run_does_not_update_task_when_validation_fails(): void
    {
        $record = $this->createTaskRecord(
            'test-run-no-update',
            null,
            UniqueTaskStatus::PENDING,
            now()->addHours(2)
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);

        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::PENDING, $task->getStatus());
        $this->assertNull($task->getFinishedAt());

        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(0, $debugs);
    }
}
