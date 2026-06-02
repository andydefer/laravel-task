<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Tests\UnitTestCase;

final class TaskStorageTest extends UnitTestCase
{
    private string $tempDir;

    private TaskStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/task_storage_test_' . uniqid();
        $this->storage = new TaskStorage($this->tempDir);
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
        $payloadCollection = new StrictDataObjectCollection();
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'storage_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );
    }

    private function createTestTask(
        string $id = '123',
        bool $enforceExactSchedule = false,
        TaskStatus $status = TaskStatus::PENDING
    ): TaskRecord {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: $id,
            signature: 'test',
            class: 'TestClass',
            payload: $payload,
            mode: TaskMode::SYNC,
            status: $status,
            createdAt: date('c'),
            startAt: date('c'),
            endAt: null,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_save_and_find_pending_task(): void
    {
        // Arrange: Create a pending task
        $task = $this->createTestTask();

        // Act: Save the task and find pending tasks
        $this->storage->savePending($task);
        $pending = $this->storage->findPending();

        // Assert: Task was saved and found
        $this->assertSame(1, $pending->count());
    }

    public function test_save_pending_task_with_enforce_exact_schedule(): void
    {
        // Arrange: Create a task with enforce exact schedule enabled
        $task = $this->createTestTask(enforceExactSchedule: true);

        // Act: Save the task and retrieve it
        $this->storage->savePending($task);
        $pending = $this->storage->findPending();
        $savedTask = $pending->first();

        // Assert: Task was saved with enforceExactSchedule = true
        $this->assertNotNull($savedTask);
        $this->assertTrue($savedTask->enforceExactSchedule);
    }

    public function test_save_pending_task_without_enforce_exact_schedule(): void
    {
        // Arrange: Create a task with enforce exact schedule disabled
        $task = $this->createTestTask(enforceExactSchedule: false);

        // Act: Save the task and retrieve it
        $this->storage->savePending($task);
        $pending = $this->storage->findPending();
        $savedTask = $pending->first();

        // Assert: Task was saved with enforceExactSchedule = false
        $this->assertNotNull($savedTask);
        $this->assertFalse($savedTask->enforceExactSchedule);
    }

    public function test_delete_pending_task(): void
    {
        // Arrange: Save a pending task
        $task = $this->createTestTask();
        $this->storage->savePending($task);

        // Act: Delete the task
        $this->storage->deletePending('123');
        $pending = $this->storage->findPending();

        // Assert: Task was deleted
        $this->assertSame(0, $pending->count());
    }

    public function test_delete_nonexistent_pending_task_does_nothing(): void
    {
        // Arrange: No task saved

        // Act: Delete a non-existent task
        $this->storage->deletePending('nonexistent-id');
        $pending = $this->storage->findPending();

        // Assert: No error and pending count remains 0
        $this->assertSame(0, $pending->count());
    }

    public function test_find_pending_returns_only_pending_tasks(): void
    {
        // Arrange: Save one pending task and one non-pending task
        $pendingTask = $this->createTestTask('pending-1', enforceExactSchedule: false, status: TaskStatus::PENDING);
        $runningTask = $this->createTestTask('running-1', enforceExactSchedule: false, status: TaskStatus::RUNNING);

        $this->storage->savePending($pendingTask);
        $this->storage->savePending($runningTask);

        // Act: Find pending tasks
        $pending = $this->storage->findPending();

        // Assert: Only pending tasks are returned
        $this->assertSame(1, $pending->count());

        $foundTask = $pending->first();
        $this->assertSame('pending-1', $foundTask->id);
        $this->assertSame(TaskStatus::PENDING, $foundTask->status);
    }

    public function test_find_pending_returns_empty_collection_when_no_tasks(): void
    {
        // Arrange: No tasks saved

        // Act: Find pending tasks
        $pending = $this->storage->findPending();

        // Assert: Empty collection returned
        $this->assertSame(0, $pending->count());
    }

    public function test_task_to_array_includes_enforce_exact_schedule(): void
    {
        // Arrange: Create a task with enforceExactSchedule = true
        $task = $this->createTestTask(enforceExactSchedule: true);

        // Act: Convert task to array using toArray() method
        $array = $task->toArray();

        // Assert: Array contains enforce_exact_schedule key with correct value
        $this->assertArrayHasKey('enforce_exact_schedule', $array);
        $this->assertTrue($array['enforce_exact_schedule']);
    }

    public function test_task_to_array_includes_all_required_fields(): void
    {
        // Arrange: Create a task
        $task = $this->createTestTask();

        // Act: Convert task to array using toArray() method
        $array = $task->toArray();

        // Assert: Array contains all required fields
        $expectedKeys = [
            'id',
            'signature',
            'class',
            'payload',
            'mode',
            'status',
            'created_at',
            'start_at',
            'end_at',
            'delay_seconds',
            'attempts',
            'max_attempts',
            'last_error',
            'enforce_exact_schedule',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "Array should contain key: {$key}");
        }
    }

    public function test_multiple_tasks_can_be_saved_and_retrieved(): void
    {
        // Arrange: Create multiple tasks
        $task1 = $this->createTestTask('task-1');
        $task2 = $this->createTestTask('task-2');
        $task3 = $this->createTestTask('task-3');

        // Act: Save all tasks and retrieve them
        $this->storage->savePending($task1);
        $this->storage->savePending($task2);
        $this->storage->savePending($task3);
        $pending = $this->storage->findPending();

        // Assert: All tasks were saved
        $this->assertSame(3, $pending->count());
    }

    public function test_save_pending_task_overwrites_existing_task(): void
    {
        // Arrange: Save a task
        $originalTask = $this->createTestTask('overwrite-test');
        $this->storage->savePending($originalTask);

        // Act: Save a different task with same ID
        $modifiedTask = $this->createTestTask('overwrite-test', enforceExactSchedule: true);
        $this->storage->savePending($modifiedTask);
        $pending = $this->storage->findPending();
        $savedTask = $pending->first();

        // Assert: Task was overwritten
        $this->assertSame(1, $pending->count());
        $this->assertTrue($savedTask->enforceExactSchedule);
    }

    public function test_task_preserves_payload_after_save(): void
    {
        // Arrange: Create a task with custom payload
        $task = $this->createTestTask();

        // Act: Save and retrieve the task
        $this->storage->savePending($task);
        $pending = $this->storage->findPending();
        $savedTask = $pending->first();

        // Assert: Payload was preserved
        $this->assertNotNull($savedTask);
        $this->assertSame($task->payload->type, $savedTask->payload->type);
        $this->assertSame($task->payload->payload->count(), $savedTask->payload->payload->count());
    }
}
