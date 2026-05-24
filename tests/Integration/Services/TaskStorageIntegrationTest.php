<?php

// tests/Integration/Services/TaskStorageIntegrationTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class TaskStorageIntegrationTest extends IntegrationTestCase
{
    private string $tempDir;

    private TaskStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/task_storage_integration_'.uniqid();
        $this->storage = new TaskStorage($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_save_and_find_recurring_task(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

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

        $this->storage->saveRecurring($task);

        $found = $this->storage->getRecurring('recurring-test');

        $this->assertNotNull($found);
        $this->assertSame('recurring-test', $found->signature);
    }

    public function test_update_recurring_after_run(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

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

        $this->storage->updateRecurringAfterRun($task, true, null);

        $updated = $this->storage->getRecurring('recurring-test');

        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->successCount);
        $this->assertNotNull($updated->lastRunAt);
    }

    public function test_move_to_completed(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

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
        $this->storage->moveToCompleted($task, true);

        $pending = $this->storage->findPending();

        $this->assertSame(0, $pending->count());
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
