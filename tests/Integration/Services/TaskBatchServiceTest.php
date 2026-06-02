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
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\Stub;

final class TaskBatchServiceTest extends IntegrationTestCase
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

        // Mock TaskConfig
        $this->config = $this->createStub(TaskConfig::class);
        $this->config->method('storagePath')->willReturn($this->storagePath);
        $this->config->method('storagePendingPath')->willReturn($this->storagePath . '/pending');
        $this->config->method('storageRecurringPath')->willReturn($this->storagePath . '/recurring');
        $this->config->method('storageCompletedPath')->willReturn($this->storagePath . '/completed');
        $this->config->method('batchLimit')->willReturn(1000);
        $this->config->method('batchOrder')->willReturn('oldest');
        $this->config->method('gracePeriodEnabled')->willReturn(false);
        $this->config->method('getEffectiveLimit')->willReturnCallback(function ($limit) {
            if ($limit === 0) {
                return 0;
            }
            if ($limit !== null) {
                return $limit;
            }

            return 1000;
        });

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
        $payloadCollection->add(StrictDataObject::from(['test_data' => 'batch_test']));

        return new TaskPayloadRecord(type: 'test', payload: $payloadCollection);
    }

    private function createUniqueTask(string $id, string $signature = 'test-task'): TaskRecord
    {
        return new TaskRecord(
            id: $id,
            signature: $signature,
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

    private function createFailingUniqueTask(string $id): TaskRecord
    {
        return new TaskRecord(
            id: $id,
            signature: 'failing-task',
            class: FailingTask::class,
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

    private function createRecurringTask(string $signature): RecurringTaskRecord
    {
        return new RecurringTaskRecord(
            signature: $signature,
            class: TestTask::class,
            payload: $this->createTaskPayload(),
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 0,
            failureCount: 0,
        );
    }

    public function test_process_processes_all_pending_unique_tasks(): void
    {
        // Arrange: Create 3 pending unique tasks
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createUniqueTask("unique-{$i}");
            $this->storage->savePending($task);
        }

        // Act: Process all tasks
        $record = $this->batch->process();

        // Assert: All unique tasks were processed
        $this->assertSame(3, $record->uniqueSuccess);
        $this->assertSame(0, $record->uniqueFailed);
        $this->assertSame(0, $record->recurringSuccess);
        $this->assertSame(0, $record->recurringFailed);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_process_processes_all_pending_recurring_tasks(): void
    {
        // Arrange: Create 3 pending recurring tasks
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createRecurringTask("recurring-{$i}");
            $this->storage->saveRecurring($task);
        }

        // Act: Process all tasks
        $record = $this->batch->process();

        // Assert: All recurring tasks were processed
        $this->assertSame(0, $record->uniqueSuccess);
        $this->assertSame(0, $record->uniqueFailed);
        $this->assertSame(3, $record->recurringSuccess);
        $this->assertSame(0, $record->recurringFailed);
    }

    public function test_process_unique_only_processes_only_unique_tasks(): void
    {
        // Arrange: Create both unique and recurring tasks
        $uniqueTask = $this->createUniqueTask('unique-1');
        $recurringTask = $this->createRecurringTask('recurring-1');
        $this->storage->savePending($uniqueTask);
        $this->storage->saveRecurring($recurringTask);

        // Act: Process only unique tasks
        $record = $this->batch->processUniqueOnly();

        // Assert: Only unique task was processed
        $this->assertSame(1, $record->uniqueSuccess);
        $this->assertSame(0, $record->recurringSuccess);

        // Verify recurring task still exists
        $recurring = $this->storage->findRecurring();
        $this->assertSame(1, $recurring->count());
    }

    public function test_process_recurring_only_processes_only_recurring_tasks(): void
    {
        // Arrange: Create both unique and recurring tasks
        $uniqueTask = $this->createUniqueTask('unique-1');
        $recurringTask = $this->createRecurringTask('recurring-1');
        $this->storage->savePending($uniqueTask);
        $this->storage->saveRecurring($recurringTask);

        // Act: Process only recurring tasks
        $record = $this->batch->processRecurringOnly();

        // Assert: Only recurring task was processed
        $this->assertSame(0, $record->uniqueSuccess);
        $this->assertSame(1, $record->recurringSuccess);

        // Verify unique task still exists
        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());
    }

    public function test_process_handles_failing_tasks_gracefully(): void
    {
        // Arrange: Create a mix of successful and failing tasks
        $successTask = $this->createUniqueTask('success-1');
        $failingTask = $this->createFailingUniqueTask('failing-1');
        $this->storage->savePending($successTask);
        $this->storage->savePending($failingTask);

        // Act: Process all tasks
        $record = $this->batch->process();

        // Assert: One success, one failure
        $this->assertSame(1, $record->uniqueSuccess);
        $this->assertSame(1, $record->uniqueFailed);

        // Check errors collection
        $this->assertFalse($record->errors->isEmpty());

        // Find the failing task error
        $failingError = $record->errors->find(fn($error) => $error->taskId === 'failing-1');
        $this->assertNotNull($failingError);
    }

    public function test_process_returns_correct_statistics(): void
    {
        // Arrange: Create tasks
        for ($i = 1; $i <= 2; $i++) {
            $task = $this->createUniqueTask("unique-{$i}");
            $this->storage->savePending($task);
        }
        for ($i = 1; $i <= 2; $i++) {
            $task = $this->createRecurringTask("recurring-{$i}");
            $this->storage->saveRecurring($task);
        }

        // Act
        $record = $this->batch->process();

        // Assert
        $this->assertSame(2, $record->uniqueSuccess);
        $this->assertSame(0, $record->uniqueFailed);
        $this->assertSame(2, $record->recurringSuccess);
        $this->assertSame(0, $record->recurringFailed);
    }

    public function test_process_empty_queue_returns_empty_result(): void
    {
        // Act
        $record = $this->batch->process();

        // Assert
        $this->assertSame(0, $record->uniqueSuccess);
        $this->assertSame(0, $record->uniqueFailed);
        $this->assertSame(0, $record->recurringSuccess);
        $this->assertSame(0, $record->recurringFailed);
        $this->assertTrue($record->errors->isEmpty());
    }

    public function test_process_unique_only_on_empty_queue(): void
    {
        // Act
        $record = $this->batch->processUniqueOnly();

        // Assert
        $this->assertSame(0, $record->uniqueSuccess);
        $this->assertSame(0, $record->uniqueFailed);
        $this->assertTrue($record->errors->isEmpty());
    }

    public function test_process_recurring_only_on_empty_queue(): void
    {
        // Act
        $record = $this->batch->processRecurringOnly();

        // Assert
        $this->assertSame(0, $record->recurringSuccess);
        $this->assertSame(0, $record->recurringFailed);
        $this->assertTrue($record->errors->isEmpty());
    }
}
