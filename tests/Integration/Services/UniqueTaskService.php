<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Configs\UniqueTaskConfig;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTaskWithCustomConfig;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Ramsey\Uuid\UuidFactory;

final class UniqueTaskServiceTest extends IntegrationTestCase
{
    private UniqueTaskService $service;

    private UniqueTaskRepositoryInterface $repository;

    private string $storagePath;

    private FileSystemService $fs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fs = new FileSystemService;
        $this->storagePath = storage_path('test/unique_tasks');

        if ($this->fs->isDirectory($this->storagePath)) {
            $this->fs->deleteDirectory($this->storagePath);
        }
        $this->fs->makeDirectory($this->storagePath, PermissionMode::DIRECTORY, true);

        $jsonl = $this->app->make(JsonlService::class);

        $hydration = new HydrationService;
        $logger = $this->createMock(LoggerInterface::class);
        $uuidFactory = new UuidFactory;

        $this->repository = new UniqueTaskRepository(
            $jsonl,
            $hydration,
            $this->fs,
            storage_path('test'),
        );

        $this->service = new UniqueTaskService(
            $this->repository,
            $logger,
            $hydration,
            $uuidFactory,
            $this->app,
        );
    }

    protected function tearDown(): void
    {
        if ($this->fs->isDirectory($this->storagePath)) {
            $this->fs->deleteDirectory($this->storagePath);
        }
        parent::tearDown();
    }

    public function test_registers_unique_task(): void
    {
        $payload = StrictDataObject::from([
            'user_id' => 123,
            'message' => 'Hello',
        ]);

        $taskId = $this->service->register(TestUniqueTask::class, $payload);

        $this->assertInstanceOf(TaskIdVO::class, $taskId);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $taskId->value);

        $record = $this->repository->find($taskId);
        $this->assertNotNull($record);
        $this->assertEquals('test-unique', $record->alias->value);
        $this->assertEquals(TestUniqueTask::class, $record->fqcn);
        $this->assertEquals(UniqueTaskStatus::PENDING, $record->status);
        $this->assertEquals(123, $record->payload->user_id);
    }

    public function test_registers_unique_task_with_custom_config(): void
    {
        $payload = StrictDataObject::from([
            'user_id' => 456,
            'message' => 'Custom',
        ]);

        $config = new UniqueTaskConfig(
            alias: new TaskSignatureVO('custom-alias'),
            description: 'Custom description',
            scheduled_at: new Iso8601DateTimeVO(now()->addHours(2)->toIso8601String()),
            max_attempts: new CounterVO(5),
        );

        $taskId = $this->service->register(TestUniqueTaskWithCustomConfig::class, $payload, $config);

        $record = $this->repository->find($taskId);
        $this->assertNotNull($record);
        $this->assertEquals('custom-alias', $record->alias->value);
        $this->assertEquals(5, $record->max_attempts->value);
    }

    public function test_throws_exception_for_invalid_task_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend UniqueTask');

        $payload = StrictDataObject::from(['test' => 'data']);
        $this->service->register('InvalidClass', $payload);
    }

    public function test_runs_task_successfully(): void
    {
        $payload = StrictDataObject::from([
            'user_id' => 789,
            'message' => 'Run success',
        ]);

        $taskId = $this->service->register(TestUniqueTask::class, $payload);

        // Set scheduled_at in the past to make it ready
        $record = $this->repository->find($taskId);
        $updatedRecord = new UniqueTaskRecord(
            id: $record->id,
            alias: $record->alias,
            fqcn: $record->fqcn,
            payload: $record->payload,
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            status: $record->status,
            max_attempts: $record->max_attempts,
        );
        $this->repository->save($updatedRecord);

        $result = $this->service->run($taskId);
        $this->assertTrue($result);

        $record = $this->repository->find($taskId);
        $this->assertNull($record);
    }

    public function test_returns_false_for_nonexistent_task(): void
    {
        $taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655449999');
        $result = $this->service->run($taskId);
        $this->assertFalse($result);
    }

    public function test_returns_false_for_completed_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(TestUniqueTask::class, $payload);

        $record = $this->repository->find($taskId);
        $this->repository->moveToCompleted($record, true);

        $result = $this->service->run($taskId);
        $this->assertFalse($result);
    }

    public function test_handles_task_failure_and_retry(): void
    {
        // Create a task that will fail
        $payload = StrictDataObject::from([
            'user_id' => 999,
            'message' => 'Fail then retry',
        ]);

        $taskId = $this->service->register(TestUniqueTask::class, $payload);

        // Set scheduled_at in the past
        $record = $this->repository->find($taskId);
        $updatedRecord = new UniqueTaskRecord(
            id: $record->id,
            alias: $record->alias,
            fqcn: $record->fqcn,
            payload: $record->payload,
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            status: $record->status,
            max_attempts: new CounterVO(3),
        );
        $this->repository->save($updatedRecord);

        // First attempt should fail but retry
        $result = $this->service->run($taskId);
        $this->assertFalse($result);

        $record = $this->repository->find($taskId);
        $this->assertNotNull($record);
        $this->assertEquals(1, $record->attempts->value);
        $this->assertEquals(UniqueTaskStatus::PENDING, $record->status);
        $this->assertEquals('Task execution failed', $record->last_error);
    }

    public function test_processes_pending_tasks(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $payload = StrictDataObject::from(['index' => $i]);
            $taskId = $this->service->register(TestUniqueTask::class, $payload);

            // Set scheduled_at in the past
            $record = $this->repository->find($taskId);
            $updatedRecord = new UniqueTaskRecord(
                id: $record->id,
                alias: $record->alias,
                fqcn: $record->fqcn,
                payload: $record->payload,
                scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(5)->toIso8601String()),
                status: $record->status,
                max_attempts: $record->max_attempts,
            );
            $this->repository->save($updatedRecord);
        }

        $results = $this->service->process();
        $this->assertEquals(3, $results['success']);
        $this->assertEquals(0, $results['failed']);

        $pending = $this->repository->findPending();
        $this->assertCount(0, $pending);
    }

    public function test_processes_with_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $payload = StrictDataObject::from(['index' => $i]);
            $taskId = $this->service->register(TestUniqueTask::class, $payload);

            $record = $this->repository->find($taskId);
            $updatedRecord = new UniqueTaskRecord(
                id: $record->id,
                alias: $record->alias,
                fqcn: $record->fqcn,
                payload: $record->payload,
                scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(5)->toIso8601String()),
                status: $record->status,
                max_attempts: $record->max_attempts,
            );
            $this->repository->save($updatedRecord);
        }

        $results = $this->service->process(3);
        $this->assertEquals(3, $results['success']);
        $this->assertEquals(0, $results['failed']);

        $pending = $this->repository->findPending();
        $this->assertCount(2, $pending);
    }

    public function test_deletes_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'delete']);
        $taskId = $this->service->register(TestUniqueTask::class, $payload);

        $record = $this->repository->find($taskId);
        $this->assertNotNull($record);

        $this->service->delete($taskId);

        $record = $this->repository->find($taskId);
        $this->assertNull($record);
    }

    public function test_finds_task_by_id(): void
    {
        $payload = StrictDataObject::from(['test' => 'find']);
        $taskId = $this->service->register(TestUniqueTask::class, $payload);

        $record = $this->service->find($taskId);
        $this->assertNotNull($record);
        $this->assertEquals($taskId->value, $record->id->value);
    }

    public function test_returns_null_when_task_not_found(): void
    {
        $taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655449999');
        $record = $this->service->find($taskId);
        $this->assertNull($record);
    }
}
