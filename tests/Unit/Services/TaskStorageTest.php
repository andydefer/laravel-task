<?php

// tests/Unit/Services/TaskStorageTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
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

    private function createTestTask(bool $enforceExactSchedule = false): TaskRecord
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        return new TaskRecord(
            id: '123',
            signature: 'test',
            class: 'TestClass',
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c'),
            endAt: null,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    public function test_save_and_find_pending_task(): void
    {
        $task = $this->createTestTask();
        $this->storage->savePending($task);

        $pending = $this->storage->findPending();

        $this->assertSame(1, $pending->count());
    }

    public function test_save_pending_task_with_enforce_exact_schedule(): void
    {
        $task = $this->createTestTask(enforceExactSchedule: true);
        $this->storage->savePending($task);

        $pending = $this->storage->findPending();
        $savedTask = $pending->firstItem();

        $this->assertNotNull($savedTask);
        $this->assertTrue($savedTask->enforceExactSchedule);
    }

    public function test_delete_pending_task(): void
    {
        $task = $this->createTestTask();
        $this->storage->savePending($task);
        $this->storage->deletePending('123');

        $pending = $this->storage->findPending();

        $this->assertSame(0, $pending->count());
    }

    public function test_task_to_array_includes_enforce_exact_schedule(): void
    {
        $task = $this->createTestTask(enforceExactSchedule: true);

        $reflection = new \ReflectionClass($this->storage);
        $method = $reflection->getMethod('taskToArray');

        $array = $method->invoke($this->storage, $task);

        $this->assertArrayHasKey('enforce_exact_schedule', $array);
        $this->assertTrue($array['enforce_exact_schedule']);
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
}
