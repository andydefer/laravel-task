<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;

final class TaskStorageServiceIntegrationTest extends UnitTestCase
{
    private string $tempDir;

    private TaskStorageService $storage;

    private TaskConfig&Stub $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/task_storage_integration_' . uniqid();

        // Create mock config with all required methods
        $this->config = $this->createStub(TaskConfig::class);
        $this->config->method('storagePath')->willReturn($this->tempDir);
        $this->config->method('storagePendingPath')->willReturn($this->tempDir . '/pending');
        $this->config->method('storageRecurringPath')->willReturn($this->tempDir . '/recurring');
        $this->config->method('storageCompletedPath')->willReturn($this->tempDir . '/completed');

        $this->storage = new TaskStorageService($this->config);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection;
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'sample',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_save_and_find_recurring_task(): void
    {
        // Arrange: Create a recurring task
        $payload = $this->createTaskPayload();

        $task = new RecurringTaskRecord(
            signature: 'recurring-test',
            class: 'TestClass',
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c'),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-1 minute')),
            successCount: 0,
            failureCount: 0,
        );

        // Act: Save and retrieve the recurring task
        $this->storage->saveRecurring($task);
        $found = $this->storage->getRecurring('recurring-test');

        // Assert: Task was saved and retrieved correctly
        $this->assertNotNull($found);
        $this->assertSame('recurring-test', $found->signature);
    }

    public function test_update_recurring_after_run(): void
    {
        // Arrange: Create a recurring task
        $payload = $this->createTaskPayload();

        $task = new RecurringTaskRecord(
            signature: 'recurring-test',
            class: 'TestClass',
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c'),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c'),
            successCount: 0,
            failureCount: 0,
        );

        $this->storage->saveRecurring($task);

        // Act: Update the recurring task after successful run
        $this->storage->updateRecurringAfterRun($task, true, null);
        $updated = $this->storage->getRecurring('recurring-test');

        // Assert: Task was updated correctly
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->successCount);
        $this->assertNotNull($updated->lastRunAt);
    }

    public function test_move_to_completed(): void
    {
        // Arrange: Create and save a pending task
        $payload = $this->createTaskPayload();

        $task = new TaskRecord(
            id: '123',
            signature: 'test',
            class: 'TestClass',
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c'),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        // Act: Move the task to completed
        $this->storage->moveToCompleted($task);
        $pending = $this->storage->findPending();

        // Assert: Task was moved out of pending
        $this->assertSame(0, $pending->count());
    }

    public function test_find_pending_with_limit_and_order(): void
    {
        // Arrange: Create multiple pending tasks
        for ($i = 1; $i <= 5; $i++) {
            $payload = $this->createTaskPayload();
            $task = new TaskRecord(
                id: (string) $i,
                signature: 'test-' . $i,
                class: 'TestClass',
                payload: $payload,
                mode: TaskMode::SYNC,
                status: TaskStatus::PENDING,
                createdAt: date('c'),
                startAt: date('c', strtotime('-1 hour')),
                endAt: date('c', strtotime('+1 hour')),
                delaySeconds: 0,
                attempts: 0,
                maxAttempts: 3,
            );
            $this->storage->savePending($task);
            usleep(10000); // Ensure different timestamps
        }

        // Act: Find pending tasks with limit 3
        $found = $this->storage->findPending(3, 'oldest');

        // Assert: Only 3 tasks returned
        $this->assertSame(3, $found->count());
    }

    public function test_delete_pending(): void
    {
        // Arrange: Create and save a pending task
        $payload = $this->createTaskPayload();
        $task = new TaskRecord(
            id: 'to-delete',
            signature: 'test',
            class: 'TestClass',
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c'),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );
        $this->storage->savePending($task);

        // Act: Delete the pending task
        $this->storage->deletePending('to-delete');
        $found = $this->storage->findPending();

        // Assert: Task no longer exists
        $this->assertSame(0, $found->count());
    }

    public function test_delete_recurring(): void
    {
        // Arrange: Create and save a recurring task
        $payload = $this->createTaskPayload();
        $task = new RecurringTaskRecord(
            signature: 'to-delete-recurring',
            class: 'TestClass',
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c'),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c'),
            successCount: 0,
            failureCount: 0,
        );
        $this->storage->saveRecurring($task);

        // Act: Delete the recurring task
        $this->storage->deleteRecurring('to-delete-recurring');
        $found = $this->storage->getRecurring('to-delete-recurring');

        // Assert: Task no longer exists
        $this->assertNull($found);
    }

    public function test_get_all_recurring(): void
    {
        // Arrange: Create multiple recurring tasks
        for ($i = 1; $i <= 3; $i++) {
            $payload = $this->createTaskPayload();
            $task = new RecurringTaskRecord(
                signature: 'recurring-' . $i,
                class: 'TestClass',
                payload: $payload,
                mode: TaskMode::DEFER,
                startAt: date('c'),
                endAt: null,
                delaySeconds: 300,
                lastRunAt: null,
                nextRunAt: date('c'),
                successCount: 0,
                failureCount: 0,
            );
            $this->storage->saveRecurring($task);
        }

        // Act: Get all recurring tasks
        $all = $this->storage->getAllRecurring();

        // Assert: All 3 tasks returned
        $this->assertSame(3, $all->count());
    }

    public function test_get_all_pending(): void
    {
        // Arrange: Create multiple pending tasks
        for ($i = 1; $i <= 3; $i++) {
            $payload = $this->createTaskPayload();
            $task = new TaskRecord(
                id: (string) $i,
                signature: 'test-' . $i,
                class: 'TestClass',
                payload: $payload,
                mode: TaskMode::SYNC,
                status: TaskStatus::PENDING,
                createdAt: date('c'),
                startAt: date('c', strtotime('-1 hour')),
                endAt: date('c', strtotime('+1 hour')),
                delaySeconds: 0,
                attempts: 0,
                maxAttempts: 3,
            );
            $this->storage->savePending($task);
        }

        // Act: Get all pending tasks
        $all = $this->storage->getAllPending();

        // Assert: All 3 tasks returned
        $this->assertSame(3, $all->count());
    }
}
