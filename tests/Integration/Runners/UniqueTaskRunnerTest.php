<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Runners;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
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
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
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

        Carbon::setTestNow(Carbon::create(2026, 7, 5, 18, 58, 52));

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new UniqueTaskRepository(
            $this->debugRepository,
            App::make(LoggerInterface::class)
        );
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
        Carbon::setTestNow(null);
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

    private function createTaskRecord(
        string $aliasName,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?Iso8601DateTimeVO $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3,
        ?string $fqcn = null
    ): UniqueTaskRecord {
        $scheduledAt = $scheduledAt ?? new Iso8601DateTimeVO;
        $id = $id ?? $this->getUuidForAlias($aliasName);
        $fqcn = $fqcn ?? TestUniqueTask::class;
        $alias = $this->generateAliasFromName($aliasName, $id);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $alias,
            'fqcn' => new UniqueTaskFqcnVO($fqcn),
            'payload' => StrictDataObject::from(['test' => 'runner']),
            'scheduled_at' => $scheduledAt,
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
        return $this->repository->findById(new UuidVO($id));
    }

    private function findDebugByAlias(TaskAliasVO $alias)
    {
        return $this->debugRepository->findByAlias($alias);
    }

    // ==================== TESTS ====================

    public function test_run_successfully_executes_task(): void
    {
        $record = $this->createTaskRecord('test-run-success');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertGreaterThanOrEqual(0, $result->execution_time->getValue());

        $task = $this->findTaskById($record->id->getValue());
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());

        $debugs = $this->findDebugByAlias($record->alias);
        $this->assertCount(1, $debugs);

        $debug = $debugs->first();
        $debugData = $debug->getData();
        $this->assertEquals('succeeded', $debug->getStatus()->value);
        $this->assertEquals('Task executed successfully', $debugData->toArray()['info']);
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
        $this->assertEquals($record->alias->getValue(), $result->error->alias->getValue());
        $this->assertStringContainsString('Validation failed', $result->error->description->getValue());
        $this->assertStringContainsString('Task is not in PENDING state', $result->error->description->getValue());
    }

    public function test_run_returns_failure_when_scheduled_at_in_future(): void
    {
        $scheduledAt = (new Iso8601DateTimeVO)->addSeconds(7200);

        $record = $this->createTaskRecord(
            'test-run-future',
            null,
            UniqueTaskStatus::PENDING,
            $scheduledAt
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->alias->getValue(), $result->error->alias->getValue());
        $this->assertStringContainsString('Validation failed', $result->error->description->getValue());
        $this->assertStringContainsString('Task is not ready to run', $result->error->description->getValue());
    }

    public function test_run_returns_failure_when_max_attempts_reached(): void
    {
        $record = $this->createTaskRecord(
            'test-run-max-attempts',
            null,
            UniqueTaskStatus::PENDING,
            (new Iso8601DateTimeVO)->addSeconds(-7200),
            86400,
            3,
            3
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->alias->getValue(), $result->error->alias->getValue());
        $this->assertStringContainsString('Validation failed', $result->error->description->getValue());
        $this->assertStringContainsString('Maximum attempts reached', $result->error->description->getValue());
    }

    public function test_run_returns_failure_when_task_expired(): void
    {
        $record = $this->createTaskRecord(
            'test-run-expired',
            null,
            UniqueTaskStatus::PENDING,
            (new Iso8601DateTimeVO)->addSeconds(-172800),
            3600
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->alias->getValue(), $result->error->alias->getValue());
        $this->assertStringContainsString('Validation failed', $result->error->description->getValue());
        $this->assertStringContainsString('Task has expired', $result->error->description->getValue());
    }

    public function test_run_handles_task_exception(): void
    {
        $record = $this->createTaskRecord(
            'test-run-failing',
            null,
            UniqueTaskStatus::PENDING,
            (new Iso8601DateTimeVO)->addSeconds(-7200),
            86400,
            0,
            3,
            FailingTask::class
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals('Test exception', $result->error->description->getValue());

        $task = $this->findTaskById($record->id->getValue());
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());

        $debugs = $this->findDebugByAlias($record->alias);
        $this->assertCount(1, $debugs);

        $debug = $debugs->first();
        $debugData = $debug->getData();
        $this->assertEquals('failed', $debug->getStatus()->value);
        $this->assertEquals('Test exception', $debugData->toArray()['info']);
    }

    public function test_run_returns_execution_time(): void
    {
        $record = $this->createTaskRecord('test-run-time');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertIsFloat($result->execution_time->getValue());
        $this->assertGreaterThanOrEqual(0, $result->execution_time->getValue());
    }

    public function test_run_logs_start_and_success(): void
    {
        $record = $this->createTaskRecord('test-run-logs');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $debugs = $this->findDebugByAlias($record->alias);
        $this->assertCount(1, $debugs);

        $debug = $debugs->first();
        $debugData = $debug->getData();
        $this->assertEquals('succeeded', $debug->getStatus()->value);
        $this->assertEquals('Task executed successfully', $debugData->toArray()['info']);
    }

    public function test_run_sets_completed_status_on_success(): void
    {
        $record = $this->createTaskRecord('test-run-completed-status');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $task = $this->findTaskById($record->id->getValue());
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
            (new Iso8601DateTimeVO)->addSeconds(-7200),
            86400,
            0,
            3,
            FailingTask::class
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);

        $task = $this->findTaskById($record->id->getValue());
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    public function test_run_does_not_change_other_task_data(): void
    {
        $aliasName = 'test-run-data';
        $id = $this->getUuidForAlias($aliasName);
        $alias = $this->generateAliasFromName($aliasName, $id);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $alias,
            'fqcn' => new UniqueTaskFqcnVO(TestUniqueTask::class),
            'payload' => StrictDataObject::from(['test' => 'runner', 'data' => 'should_persist']),
            'scheduled_at' => (new Iso8601DateTimeVO)->addSeconds(-7200),
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
            (new Iso8601DateTimeVO)->addSeconds(-7200),
            86400,
            0,
            3,
            TestUniqueTaskWithCustomConfig::class
        );

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);

        $task = $this->findTaskById($record->id->getValue());
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
    }

    public function test_run_handles_null_payload(): void
    {
        $id = $this->getUuidForAlias('test-null-payload');
        $alias = $this->generateAliasFromName('test-null-payload', $id);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $alias,
            'fqcn' => new UniqueTaskFqcnVO(TestUniqueTask::class),
            'payload' => StrictDataObject::from([]),
            'scheduled_at' => (new Iso8601DateTimeVO)->addSeconds(-7200),
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

        $debugs = $this->findDebugByAlias($record->alias);
        $this->assertCount(1, $debugs);

        $debug = $debugs->first();
        $debugData = $debug->getData();
        $this->assertEquals('succeeded', $debug->getStatus()->value);
        $this->assertEquals('Task executed successfully', $debugData->toArray()['info']);
    }

    public function test_run_adds_debug_on_failure(): void
    {
        $record = $this->createTaskRecord(
            'test-run-debug-failure',
            null,
            UniqueTaskStatus::PENDING,
            (new Iso8601DateTimeVO)->addSeconds(-7200),
            86400,
            0,
            3,
            FailingTask::class
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);

        $debugs = $this->findDebugByAlias($record->alias);
        $this->assertCount(1, $debugs);

        $debug = $debugs->first();
        $debugData = $debug->getData();
        $this->assertEquals('failed', $debug->getStatus()->value);
        $this->assertEquals('Test exception', $debugData->toArray()['info']);
    }

    public function test_run_does_not_update_task_when_validation_fails(): void
    {
        $scheduledAt = (new Iso8601DateTimeVO)->addSeconds(7200);

        $record = $this->createTaskRecord(
            'test-run-no-update',
            null,
            UniqueTaskStatus::PENDING,
            $scheduledAt
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);

        $task = $this->findTaskById($record->id->getValue());
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::PENDING, $task->getStatus());
        $this->assertNull($task->getFinishedAt());

        $debugs = $this->findDebugByAlias($record->alias);
        $this->assertCount(0, $debugs);
    }
}
