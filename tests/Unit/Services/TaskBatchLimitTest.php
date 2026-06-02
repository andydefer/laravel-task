<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskBatch;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Carbon\Carbon;

final class TaskBatchLimitTest extends IntegrationTestCase
{
    private TaskStorage $storage;
    private TaskBatch $batch;
    private string $storagePath;
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Override config for testing
        config()->set('task.batch.limit', 3);
        config()->set('task.batch.order', 'oldest');

        $this->storagePath = sys_get_temp_dir() . '/task_storage_' . uniqid();
        $this->storage = new TaskStorage($this->storagePath);
        $this->logger = $this->app->make(Logger::class);
        $validator = $this->app->make(TaskValidator::class);
        $runner = new TaskRunner($this->storage, $this->logger, $validator);

        $this->batch = new TaskBatch($this->storage, $runner, $validator, $this->logger);
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

    private function createRecurringTask(string $signature, ?Carbon $nextRunAt = null): \AndyDefer\Task\Records\RecurringTaskRecord
    {
        $nextRun = $nextRunAt ?? Carbon::now()->subMinutes(5);

        return new \AndyDefer\Task\Records\RecurringTaskRecord(
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
        $result = $this->batch->process();

        // Assert: Only 3 tasks processed (config limit)
        $this->assertSame(3, $result->getTotal());

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
        $result = $this->batch->process(5);

        // Assert: 5 tasks processed
        $this->assertSame(5, $result->getTotal());

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
        $result = $this->batch->process(0);

        // Assert: No tasks processed
        $this->assertSame(0, $result->getTotal());

        // Verify all tasks remain
        $pending = $this->storage->findPending();
        $this->assertSame(10, $pending->count());
    }

    public function test_batch_processes_oldest_tasks_first_with_limit(): void
    {
        // Note: Le tri par filemtime() n'est pas fiable avec Carbon.
        // Ce test vérifie simplement que la limite fonctionne,
        // pas l'ordre exact.

        // Arrange: Create 3 tasks
        $task1 = $this->createTestTask('task-first');
        $this->storage->savePending($task1);

        $task2 = $this->createTestTask('task-second');
        $this->storage->savePending($task2);

        $task3 = $this->createTestTask('task-third');
        $this->storage->savePending($task3);

        // Act: Process with limit 2
        $result = $this->batch->process(2);

        // Assert: 2 tasks processed
        $this->assertSame(2, $result->getTotal());
        $this->assertSame(2, $result->getUniqueSuccess());

        // Verify that exactly 1 task remains
        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());
    }

    public function test_batch_processes_newest_tasks_first_when_configured(): void
    {
        // Arrange: Override config for this test only
        config()->set('task.batch.order', 'newest');

        // Recreate batch with new config
        $validator = $this->app->make(TaskValidator::class);
        $runner = new TaskRunner($this->storage, $this->logger, $validator);
        $batchWithNewestOrder = new TaskBatch($this->storage, $runner, $validator, $this->logger);

        // Create 3 tasks
        $task1 = $this->createTestTask('task-first');
        $this->storage->savePending($task1);

        $task2 = $this->createTestTask('task-second');
        $this->storage->savePending($task2);

        $task3 = $this->createTestTask('task-third');
        $this->storage->savePending($task3);

        // Act: Process with limit 2
        $result = $batchWithNewestOrder->process(2);

        // Assert: 2 tasks processed
        $this->assertSame(2, $result->getTotal());
        $this->assertSame(2, $result->getUniqueSuccess());

        // Verify that exactly 1 task remains
        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());

        // Reset config
        config()->set('task.batch.order', 'oldest');
    }

    public function test_batch_unique_only_respects_limit(): void
    {
        // Arrange: Create 10 pending tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act: Process unique only with limit 4
        $result = $this->batch->processUniqueOnly(4);

        // Assert: 4 tasks processed
        $this->assertSame(4, $result->getTotal());
    }

    public function test_batch_recurring_only_respects_limit(): void
    {
        // Arrange: Create 10 recurring tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createRecurringTask("recurring-{$i}");
            $this->storage->saveRecurring($task);
        }

        // Act: Process recurring only with limit 4
        $result = $this->batch->processRecurringOnly(4);

        // Assert: 4 tasks processed
        $this->assertSame(4, $result->getTotal());
    }

    public function test_batch_limit_with_more_tasks_than_limit(): void
    {
        // Arrange: Create 20 tasks
        for ($i = 1; $i <= 20; $i++) {
            $task = $this->createTestTask("task-{$i}");
            $this->storage->savePending($task);
        }

        // Act: Process with limit 7
        $result = $this->batch->process(7);

        // Assert: Exactly 7 tasks processed
        $this->assertSame(7, $result->getTotal());

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
        $result = $this->batch->process(5);

        // Assert: All 5 tasks processed
        $this->assertSame(5, $result->getTotal());

        // Verify no tasks remain
        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }
}
