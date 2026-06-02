<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

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
use Carbon\Carbon;
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
    }

    private function createBatchServiceWithConfig(array $configOverrides = []): void
    {
        $this->config = $this->createStub(TaskConfig::class);

        $defaults = [
            'storagePath' => $this->storagePath,
            'storagePendingPath' => $this->storagePath . '/pending',
            'storageRecurringPath' => $this->storagePath . '/recurring',
            'storageCompletedPath' => $this->storagePath . '/completed',
            'batchLimit' => 1000,
            'batchOrder' => 'oldest',
            'gracePeriodEnabled' => false,
            'gracePeriodSeconds' => 86400,
        ];

        $config = array_merge($defaults, $configOverrides);

        $this->config->method('storagePath')->willReturn($config['storagePath']);
        $this->config->method('storagePendingPath')->willReturn($config['storagePendingPath']);
        $this->config->method('storageRecurringPath')->willReturn($config['storageRecurringPath']);
        $this->config->method('storageCompletedPath')->willReturn($config['storageCompletedPath']);
        $this->config->method('batchLimit')->willReturn($config['batchLimit']);
        $this->config->method('batchOrder')->willReturn($config['batchOrder']);
        $this->config->method('gracePeriodEnabled')->willReturn($config['gracePeriodEnabled']);
        $this->config->method('gracePeriodSeconds')->willReturn($config['gracePeriodSeconds']);
        $this->config->method('getEffectiveLimit')->willReturnCallback(function ($limit) use ($config) {
            if ($limit === 0) {
                return 0;
            }
            if ($limit !== null) {
                return $limit;
            }
            return $config['batchLimit'];
        });

        $this->storage = new TaskStorageService($this->config);
        $this->logger = $this->app->make(Logger::class);
        $validator = new TaskValidatorService($this->config);
        $runner = new TaskRunnerService($this->storage, $this->logger, $validator);
        $batchResultService = new BatchResultService();

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
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . '/*') as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($path);
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection();
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

    private function createRecurringTaskWithNextRun(string $signature, ?Carbon $nextRunAt = null): RecurringTaskRecord
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

    // ==================== Basic Processing Tests ====================

    public function test_process_processes_all_pending_unique_tasks(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig();

        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createUniqueTask("unique-{$i}");
            $this->storage->savePending($task);
        }

        // Act
        $record = $this->batch->process();

        // Assert
        $this->assertSame(3, $record->uniqueSuccess);
        $this->assertSame(0, $record->uniqueFailed);
        $this->assertSame(0, $record->recurringSuccess);
        $this->assertSame(0, $record->recurringFailed);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_process_processes_all_pending_recurring_tasks(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig();

        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createRecurringTask("recurring-{$i}");
            $this->storage->saveRecurring($task);
        }

        // Act
        $record = $this->batch->process();

        // Assert
        $this->assertSame(0, $record->uniqueSuccess);
        $this->assertSame(0, $record->uniqueFailed);
        $this->assertSame(3, $record->recurringSuccess);
        $this->assertSame(0, $record->recurringFailed);
    }

    // ==================== Filtering Tests ====================

    public function test_process_unique_only_processes_only_unique_tasks(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig();

        $uniqueTask = $this->createUniqueTask('unique-1');
        $recurringTask = $this->createRecurringTask('recurring-1');
        $this->storage->savePending($uniqueTask);
        $this->storage->saveRecurring($recurringTask);

        // Act
        $record = $this->batch->processUniqueOnly();

        // Assert
        $this->assertSame(1, $record->uniqueSuccess);
        $this->assertSame(0, $record->recurringSuccess);

        $recurring = $this->storage->findRecurring();
        $this->assertSame(1, $recurring->count());
    }

    public function test_process_recurring_only_processes_only_recurring_tasks(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig();

        $uniqueTask = $this->createUniqueTask('unique-1');
        $recurringTask = $this->createRecurringTask('recurring-1');
        $this->storage->savePending($uniqueTask);
        $this->storage->saveRecurring($recurringTask);

        // Act
        $record = $this->batch->processRecurringOnly();

        // Assert
        $this->assertSame(0, $record->uniqueSuccess);
        $this->assertSame(1, $record->recurringSuccess);

        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());
    }

    // ==================== Error Handling Tests ====================

    public function test_process_handles_failing_tasks_gracefully(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig();

        $successTask = $this->createUniqueTask('success-1');
        $failingTask = $this->createFailingUniqueTask('failing-1');
        $this->storage->savePending($successTask);
        $this->storage->savePending($failingTask);

        // Act
        $record = $this->batch->process();

        // Assert
        $this->assertSame(1, $record->uniqueSuccess);
        $this->assertSame(1, $record->uniqueFailed);
        $this->assertFalse($record->errors->isEmpty());

        $failingError = $record->errors->find(fn($error) => $error->taskId === 'failing-1');
        $this->assertNotNull($failingError);
    }

    // ==================== Statistics Tests ====================

    public function test_process_returns_correct_statistics(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig();

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

    // ==================== Empty Queue Tests ====================

    public function test_process_empty_queue_returns_empty_result(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig();

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
        // Arrange
        $this->createBatchServiceWithConfig();

        // Act
        $record = $this->batch->processUniqueOnly();

        // Assert
        $this->assertSame(0, $record->uniqueSuccess);
        $this->assertSame(0, $record->uniqueFailed);
        $this->assertTrue($record->errors->isEmpty());
    }

    public function test_process_recurring_only_on_empty_queue(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig();

        // Act
        $record = $this->batch->processRecurringOnly();

        // Assert
        $this->assertSame(0, $record->recurringSuccess);
        $this->assertSame(0, $record->recurringFailed);
        $this->assertTrue($record->errors->isEmpty());
    }

    // ==================== Limit Handling Tests ====================

    public function test_batch_respects_config_limit(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig(['batchLimit' => 3]);

        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act
        $record = $this->batch->process();

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert
        $this->assertSame(3, $totalProcessed);

        $pending = $this->storage->findPending();
        $this->assertSame(7, $pending->count());
    }

    public function test_batch_with_custom_limit_overrides_config(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig(['batchLimit' => 3]);

        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act
        $record = $this->batch->process(5);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert
        $this->assertSame(5, $totalProcessed);

        $pending = $this->storage->findPending();
        $this->assertSame(5, $pending->count());
    }

    public function test_batch_with_limit_zero_processes_nothing(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig();

        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act
        $record = $this->batch->process(0);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert
        $this->assertSame(0, $totalProcessed);

        $pending = $this->storage->findPending();
        $this->assertSame(10, $pending->count());
    }

    public function test_batch_processes_oldest_tasks_first_with_limit(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig(['batchLimit' => 1000]);

        $task1 = $this->createTestTask('task-first');
        $this->storage->savePending($task1);

        $task2 = $this->createTestTask('task-second');
        $this->storage->savePending($task2);

        $task3 = $this->createTestTask('task-third');
        $this->storage->savePending($task3);

        // Act
        $record = $this->batch->process(2);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert
        $this->assertSame(2, $totalProcessed);

        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());
    }

    public function test_batch_processes_newest_tasks_first_when_configured(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig([
            'batchLimit' => 1000,
            'batchOrder' => 'newest'
        ]);

        $task1 = $this->createTestTask('task-first');
        $this->storage->savePending($task1);

        $task2 = $this->createTestTask('task-second');
        $this->storage->savePending($task2);

        $task3 = $this->createTestTask('task-third');
        $this->storage->savePending($task3);

        // Act
        $record = $this->batch->process(2);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert
        $this->assertSame(2, $totalProcessed);

        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());
    }

    public function test_batch_unique_only_respects_limit(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig(['batchLimit' => 1000]);

        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act
        $record = $this->batch->processUniqueOnly(4);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed;

        // Assert
        $this->assertSame(4, $totalProcessed);
    }

    public function test_batch_recurring_only_respects_limit(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig(['batchLimit' => 1000]);

        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createRecurringTaskWithNextRun("recurring-{$i}");
            $this->storage->saveRecurring($task);
        }

        // Act
        $record = $this->batch->processRecurringOnly(4);

        $totalProcessed = $record->recurringSuccess + $record->recurringFailed;

        // Assert
        $this->assertSame(4, $totalProcessed);
    }

    public function test_batch_limit_with_more_tasks_than_limit(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig(['batchLimit' => 1000]);

        for ($i = 1; $i <= 20; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act
        $record = $this->batch->process(7);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert
        $this->assertSame(7, $totalProcessed);

        $pending = $this->storage->findPending();
        $this->assertSame(13, $pending->count());
    }

    public function test_batch_limit_with_exact_number(): void
    {
        // Arrange
        $this->createBatchServiceWithConfig(['batchLimit' => 1000]);

        for ($i = 1; $i <= 5; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act
        $record = $this->batch->process(5);

        $totalProcessed = $record->uniqueSuccess + $record->uniqueFailed + $record->recurringSuccess + $record->recurringFailed;

        // Assert
        $this->assertSame(5, $totalProcessed);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }
}
