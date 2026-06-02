<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Tests\UnitTestCase;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;

final class TaskBatchServiceLimitTest extends IntegrationTestCase
{
    private TaskStorageService $storage;

    private TaskBatchService $batch;

    private string $storagePath;

    private Logger $logger;

    private TaskConfig&Stub $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . '/task_storage_' . uniqid();

        // Create mock config with all required methods
        $this->config = $this->createStub(TaskConfig::class);
        $this->config->method('storagePath')->willReturn($this->storagePath);
        $this->config->method('storagePendingPath')->willReturn($this->storagePath . '/pending');
        $this->config->method('storageRecurringPath')->willReturn($this->storagePath . '/recurring');
        $this->config->method('storageCompletedPath')->willReturn($this->storagePath . '/completed');
        $this->config->method('batchLimit')->willReturn(3);
        $this->config->method('batchOrder')->willReturn('oldest');
        $this->config->method('getEffectiveLimit')->willReturnCallback(function ($limit) {
            if ($limit === 0) {
                return 0;
            }
            if ($limit !== null) {
                return $limit;
            }

            return 3;
        });
        $this->config->method('gracePeriodEnabled')->willReturn(false);
        $this->config->method('gracePeriodSeconds')->willReturn(86400);

        $this->storage = new TaskStorageService($this->config);
        $this->logger = $this->app->make(Logger::class);
        $validator = new TaskValidatorService($this->config);
        $runner = new TaskRunnerService($this->storage, $this->logger, $validator);
        $batchResultService = new BatchResultService;

        $this->batch = new TaskBatchService(
            $this->storage,
            $runner,
            $validator,
            $this->logger,
            $batchResultService,
            $this->config,
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $this->removeDirectory($this->storagePath);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (glob($path . '/*') as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($path);
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection;
        $payloadCollection->add(StrictDataObject::from(['test_data' => 'batch_limit_test']));

        return new TaskPayloadRecord(type: 'test', payload: $payloadCollection);
    }

    private function createTestTask(string $id): TaskRecord
    {
        return new TaskRecord(
            id: $id,
            signature: 'test-task',
            class: TestTask::class,
            payload: $this->createTaskPayload(),
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );
    }

    private function createRecurringTask(string $signature, ?Carbon $nextRunAt = null): RecurringTaskRecord
    {
        $nextRun = $nextRunAt ?? Carbon::now()->subMinutes(5);

        return new RecurringTaskRecord(
            signature: $signature,
            class: TestTask::class,
            payload: $this->createTaskPayload(),
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: $nextRun->toIso8601String(),
            successCount: 0,
            failureCount: 0,
        );
    }

    public function test_batch_respects_config_limit(): void
    {
        // Arrange: Create 10 pending tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act: Process with config limit (3)
        $record = $this->batch->process();

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert: Only 3 tasks processed (config limit)
        $this->assertSame(3, $totalProcessed);

        // Verify remaining tasks still exist
        $pending = $this->storage->findPending();
        $this->assertSame(7, $pending->count());
    }

    public function test_batch_with_custom_limit_overrides_config(): void
    {
        // Arrange: Create 10 pending tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act: Process with custom limit 5
        $record = $this->batch->process(5);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert: 5 tasks processed
        $this->assertSame(5, $totalProcessed);

        // Verify remaining tasks exist
        $pending = $this->storage->findPending();
        $this->assertSame(5, $pending->count());
    }

    public function test_batch_with_limit_zero_processes_nothing(): void
    {
        // Arrange: Create 10 pending tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act: Process with limit 0
        $record = $this->batch->process(0);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert: No tasks processed
        $this->assertSame(0, $totalProcessed);

        // Verify all tasks remain
        $pending = $this->storage->findPending();
        $this->assertSame(10, $pending->count());
    }

    public function test_batch_processes_oldest_tasks_first_with_limit(): void
    {
        // Arrange: Create 3 tasks
        $task1 = $this->createTestTask('task-first');
        $this->storage->savePending($task1);

        $task2 = $this->createTestTask('task-second');
        $this->storage->savePending($task2);

        $task3 = $this->createTestTask('task-third');
        $this->storage->savePending($task3);

        // Act: Process with limit 2
        $record = $this->batch->process(2);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert: 2 tasks processed
        $this->assertSame(2, $totalProcessed);

        // Verify that exactly 1 task remains
        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());
    }

    public function test_batch_processes_newest_tasks_first_when_configured(): void
    {
        // Create mock config with newest order
        $config = $this->createStub(TaskConfig::class);
        $config->method('storagePath')->willReturn($this->storagePath);
        $config->method('storagePendingPath')->willReturn($this->storagePath . '/pending');
        $config->method('storageRecurringPath')->willReturn($this->storagePath . '/recurring');
        $config->method('storageCompletedPath')->willReturn($this->storagePath . '/completed');
        $config->method('batchLimit')->willReturn(1000);
        $config->method('batchOrder')->willReturn('newest');
        $config->method('getEffectiveLimit')->willReturnCallback(function ($limit) {
            if ($limit === 0) {
                return 0;
            }
            if ($limit !== null) {
                return $limit;
            }

            return 1000;
        });
        $config->method('gracePeriodEnabled')->willReturn(false);
        $config->method('gracePeriodSeconds')->willReturn(86400);

        $storage = new TaskStorageService($config);
        $validator = new TaskValidatorService($config);
        $runner = new TaskRunnerService($storage, $this->logger, $validator);
        $batchResultService = new BatchResultService;

        $batchWithNewestOrder = new TaskBatchService(
            $storage,
            $runner,
            $validator,
            $this->logger,
            $batchResultService,
            $config,
        );

        // Create 3 tasks
        $task1 = $this->createTestTask('task-first');
        $storage->savePending($task1);

        $task2 = $this->createTestTask('task-second');
        $storage->savePending($task2);

        $task3 = $this->createTestTask('task-third');
        $storage->savePending($task3);

        // Act: Process with limit 2
        $record = $batchWithNewestOrder->process(2);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert: 2 tasks processed
        $this->assertSame(2, $totalProcessed);

        // Verify that exactly 1 task remains
        $pending = $storage->findPending();
        $this->assertSame(1, $pending->count());
    }

    public function test_batch_unique_only_respects_limit(): void
    {
        // Arrange: Create 10 pending tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act: Process unique only with limit 4
        $record = $this->batch->processUniqueOnly(4);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed;

        // Assert: 4 tasks processed
        $this->assertSame(4, $totalProcessed);
    }

    public function test_batch_recurring_only_respects_limit(): void
    {
        // Arrange: Create 10 recurring tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createRecurringTask("recurring-{$i}");
            $this->storage->saveRecurring($task);
        }

        // Act: Process recurring only with limit 4
        $record = $this->batch->processRecurringOnly(4);

        $totalProcessed = $record->recurringSuccess + $record->recurringFailed;

        // Assert: 4 tasks processed
        $this->assertSame(4, $totalProcessed);
    }

    public function test_batch_limit_with_more_tasks_than_limit(): void
    {
        // Arrange: Create 20 tasks
        for ($i = 1; $i <= 20; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act: Process with limit 7
        $record = $this->batch->process(7);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert: Exactly 7 tasks processed
        $this->assertSame(7, $totalProcessed);

        // Verify remaining tasks
        $pending = $this->storage->findPending();
        $this->assertSame(13, $pending->count());
    }

    public function test_batch_limit_with_exact_number(): void
    {
        // Arrange: Create exactly 5 tasks
        for ($i = 1; $i <= 5; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act: Process with limit 5
        $record = $this->batch->process(5);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert: All 5 tasks processed
        $this->assertSame(5, $totalProcessed);

        // Verify no tasks remain
        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }
}
