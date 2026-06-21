<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class RecurringTaskServiceTest extends IntegrationTestCase
{
    private RecurringTaskService $service;

    private RecurringTaskRepositoryInterface $repository;

    private string $storagePath;

    private FileSystemService $fs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fs = new FileSystemService;
        $this->storagePath = storage_path('test/recurring_tasks');

        if ($this->fs->isDirectory($this->storagePath)) {
            $this->fs->deleteDirectory($this->storagePath);
        }
        $this->fs->makeDirectory($this->storagePath, PermissionMode::DIRECTORY, true);

        $jsonl = $this->app->make(JsonlService::class);

        $hydration = new HydrationService;
        $logger = $this->createMock(LoggerInterface::class);

        $this->repository = new RecurringTaskRepository(
            $jsonl,
            $hydration,
            $this->fs,
            storage_path('test'),
        );

        $this->service = new RecurringTaskService(
            $this->repository,
            $logger,
            $hydration,
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

    public function test_registers_recurring_task(): void
    {
        $payload = StrictDataObject::from([
            'user_id' => 123,
            'message' => 'Recurring hello',
        ]);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring-service'),
            description: 'Test recurring service',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            max_attempts: new CounterVO(3),
        );

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $this->assertEquals('test-recurring-service', $alias->value);

        $record = $this->repository->find($alias);
        $this->assertNotNull($record);
        $this->assertEquals('test-recurring-service', $record->alias->value);
        $this->assertEquals(TestRecurringTask::class, $record->fqcn);
        $this->assertEquals(3600, $record->interval_seconds->value);
    }

    public function test_throws_exception_when_already_exists(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring-duplicate'),
            description: 'Test duplicate',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            max_attempts: new CounterVO(3),
        );

        $this->service->register(TestRecurringTask::class, $payload, $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Recurring task 'test-recurring-duplicate' already exists");

        $this->service->register(TestRecurringTask::class, $payload, $config);
    }

    public function test_throws_exception_for_invalid_task_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend RecurringTask');

        $payload = StrictDataObject::from(['test' => 'data']);
        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-invalid'),
            description: 'Invalid task',
            interval_seconds: new CounterVO(3600),
            max_attempts: new CounterVO(3),
        );
        $this->service->register('InvalidClass', $payload, $config);
    }

    public function test_runs_task_successfully(): void
    {
        $payload = StrictDataObject::from([
            'user_id' => 456,
            'message' => 'Run recurring',
        ]);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring-run'),
            description: 'Test run',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
            max_attempts: new CounterVO(3),
        );

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        // Set next_run_at in the past
        $record = $this->repository->find($alias);
        $updatedRecord = new RecurringTaskRecord(
            alias: $record->alias,
            fqcn: $record->fqcn,
            payload: $record->payload,
            interval_seconds: $record->interval_seconds,
            start_at: $record->start_at,
            end_at: $record->end_at,
            next_run_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
        );
        $this->repository->save($updatedRecord);

        $result = $this->service->run($alias);
        $this->assertTrue($result);

        $record = $this->repository->find($alias);
        $this->assertNotNull($record);
        $this->assertEquals(1, $record->success_count->value);
        $this->assertNotNull($record->last_run_at);
        $this->assertNotNull($record->next_run_at);
    }

    public function test_returns_false_for_nonexistent_task(): void
    {
        $alias = new TaskSignatureVO('nonexistent-recurring');
        $result = $this->service->run($alias);
        $this->assertFalse($result);
    }

    public function test_returns_false_when_not_ready_to_run(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring-not-ready'),
            description: 'Not ready',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            max_attempts: new CounterVO(3),
        );

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        // next_run_at is in the future
        $record = $this->repository->find($alias);
        $updatedRecord = new RecurringTaskRecord(
            alias: $record->alias,
            fqcn: $record->fqcn,
            payload: $record->payload,
            interval_seconds: $record->interval_seconds,
            start_at: $record->start_at,
            end_at: $record->end_at,
            next_run_at: new Iso8601DateTimeVO(now()->addHours(24)->toIso8601String()),
        );
        $this->repository->save($updatedRecord);

        $result = $this->service->run($alias);
        $this->assertFalse($result);
    }

    public function test_returns_false_when_task_expired(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring-expired'),
            description: 'Expired',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->subHours(48)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(now()->subHours(24)->toIso8601String()),
            max_attempts: new CounterVO(3),
        );

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $result = $this->service->run($alias);
        $this->assertFalse($result);
    }

    public function test_processes_recurring_tasks(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $payload = StrictDataObject::from(['index' => $i]);

            $config = new RecurringTaskConfig(
                alias: new TaskSignatureVO('test-recurring-process-'.$i),
                description: 'Process test '.$i,
                interval_seconds: new CounterVO(3600),
                start_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
                max_attempts: new CounterVO(3),
            );

            $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

            // Set next_run_at in the past
            $record = $this->repository->find($alias);
            $updatedRecord = new RecurringTaskRecord(
                alias: $record->alias,
                fqcn: $record->fqcn,
                payload: $record->payload,
                interval_seconds: $record->interval_seconds,
                start_at: $record->start_at,
                end_at: $record->end_at,
                next_run_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            );
            $this->repository->save($updatedRecord);
        }

        $results = $this->service->process();
        $this->assertEquals(3, $results['success']);
        $this->assertEquals(0, $results['failed']);
    }

    public function test_processes_with_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $payload = StrictDataObject::from(['index' => $i]);

            $config = new RecurringTaskConfig(
                alias: new TaskSignatureVO('test-recurring-limit-'.$i),
                description: 'Limit test '.$i,
                interval_seconds: new CounterVO(3600),
                start_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
                max_attempts: new CounterVO(3),
            );

            $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

            $record = $this->repository->find($alias);
            $updatedRecord = new RecurringTaskRecord(
                alias: $record->alias,
                fqcn: $record->fqcn,
                payload: $record->payload,
                interval_seconds: $record->interval_seconds,
                start_at: $record->start_at,
                end_at: $record->end_at,
                next_run_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            );
            $this->repository->save($updatedRecord);
        }

        $results = $this->service->process(3);
        $this->assertEquals(3, $results['success']);
        $this->assertEquals(0, $results['failed']);
    }

    public function test_finds_task_by_alias(): void
    {
        $payload = StrictDataObject::from(['test' => 'find']);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring-find'),
            description: 'Find test',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            max_attempts: new CounterVO(3),
        );

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $record = $this->service->find($alias);
        $this->assertNotNull($record);
        $this->assertEquals($alias->value, $record->alias->value);
    }

    public function test_returns_null_when_task_not_found(): void
    {
        $alias = new TaskSignatureVO('nonexistent-recurring');
        $record = $this->service->find($alias);
        $this->assertNull($record);
    }

    public function test_deletes_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'delete']);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring-delete'),
            description: 'Delete test',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            max_attempts: new CounterVO(3),
        );

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $record = $this->repository->find($alias);
        $this->assertNotNull($record);

        $this->service->delete($alias);

        $record = $this->repository->find($alias);
        $this->assertNull($record);
    }
}
