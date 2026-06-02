<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskBatch;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class TaskBatchTest extends IntegrationTestCase
{
    private TaskStorage $storage;

    private TaskBatch $batch;

    private string $storagePath;

    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function createFailingRecurringTask(string $signature): RecurringTaskRecord
    {
        return new RecurringTaskRecord(
            signature: $signature,
            class: FailingTask::class,
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
        $result = $this->batch->process();

        // Assert: All unique tasks were processed
        $this->assertSame(3, $result->getUniqueSuccess());
        $this->assertSame(0, $result->getUniqueFailed());
        $this->assertSame(0, $result->getRecurringSuccess());
        $this->assertSame(0, $result->getRecurringFailed());
        $this->assertSame(3, $result->getTotal());

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
        $result = $this->batch->process();

        // Assert: All recurring tasks were processed
        $this->assertSame(0, $result->getUniqueSuccess());
        $this->assertSame(0, $result->getUniqueFailed());
        $this->assertSame(3, $result->getRecurringSuccess());
        $this->assertSame(0, $result->getRecurringFailed());
        $this->assertSame(3, $result->getTotal());
    }

    public function test_process_unique_only_processes_only_unique_tasks(): void
    {
        // Arrange: Create both unique and recurring tasks
        $uniqueTask = $this->createUniqueTask('unique-1');
        $recurringTask = $this->createRecurringTask('recurring-1');
        $this->storage->savePending($uniqueTask);
        $this->storage->saveRecurring($recurringTask);

        // Act: Process only unique tasks
        $result = $this->batch->processUniqueOnly();

        // Assert: Only unique task was processed
        $this->assertSame(1, $result->getUniqueSuccess());
        $this->assertSame(0, $result->getRecurringSuccess());
        $this->assertSame(1, $result->getTotal());

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
        $result = $this->batch->processRecurringOnly();

        // Assert: Only recurring task was processed
        $this->assertSame(0, $result->getUniqueSuccess());
        $this->assertSame(1, $result->getRecurringSuccess());
        $this->assertSame(1, $result->getTotal());

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
        $result = $this->batch->process();

        // Assert: One success, one failure
        $this->assertSame(1, $result->getUniqueSuccess());
        $this->assertSame(1, $result->getUniqueFailed());
        $this->assertSame(2, $result->getTotal());
        $this->assertTrue($result->hasFailures());

        // Less strict assertion - just check that there are errors
        $this->assertGreaterThan(0, count($result->getErrors()));
        $this->assertArrayHasKey('failing-1', $result->getUniqueResults());
        $this->assertFalse($result->getUniqueResults()['failing-1']);
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
        $result = $this->batch->process();

        // Assert
        $this->assertSame(2, $result->getUniqueSuccess());
        $this->assertSame(0, $result->getUniqueFailed());
        $this->assertSame(2, $result->getRecurringSuccess());
        $this->assertSame(0, $result->getRecurringFailed());
        $this->assertSame(4, $result->getTotal());
        $this->assertSame(4, $result->getTotalSuccess());
        $this->assertSame(0, $result->getTotalFailed());
        $this->assertTrue($result->isSuccessful());
        $this->assertGreaterThan(0, $result->getDurationMilliseconds());
    }

    public function test_process_empty_queue_returns_empty_result(): void
    {
        // Act
        $result = $this->batch->process();

        // Assert
        $this->assertSame(0, $result->getTotal());
        $this->assertSame(0, $result->getTotalSuccess());
        $this->assertSame(0, $result->getTotalFailed());
        $this->assertTrue($result->isSuccessful());
    }

    public function test_process_unique_only_on_empty_queue(): void
    {
        // Act
        $result = $this->batch->processUniqueOnly();

        // Assert
        $this->assertSame(0, $result->getTotal());
        $this->assertTrue($result->isSuccessful());
    }

    public function test_process_recurring_only_on_empty_queue(): void
    {
        // Act
        $result = $this->batch->processRecurringOnly();

        // Assert
        $this->assertSame(0, $result->getTotal());
        $this->assertTrue($result->isSuccessful());
    }
}
