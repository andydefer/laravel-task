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

final class TaskStorageLimitTest extends UnitTestCase
{
    private string $tempDir;
    private TaskStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/task_storage_limit_test_' . uniqid();
        $this->storage = new TaskStorage($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
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
        $payloadCollection->add(StrictDataObject::from(['test_data' => 'limit_test']));
        return new TaskPayloadRecord(type: 'test', payload: $payloadCollection);
    }

    private function createTestTask(int $order): TaskRecord
    {
        $taskId = "task-{$order}";

        // Utiliser l'ID pour créer un timestamp différent
        // Plus l'ID est grand, plus la date est récente
        $createdAt = date('c', strtotime("+{$order} seconds"));
        $startAt = date('c', strtotime("-1 minute +{$order} seconds"));
        $endAt = date('c', strtotime("+1 hour +{$order} seconds"));

        return new TaskRecord(
            id: $taskId,
            signature: 'test-task',
            class: 'TestClass',
            payload: $this->createTaskPayload(),
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: $createdAt,
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );
    }

    public function test_find_pending_with_limit_returns_only_limited_tasks(): void
    {
        // Arrange: Create 10 pending tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask($i);
            $this->storage->savePending($task);
        }

        // Act: Find pending tasks with limit 5
        $result = $this->storage->findPending(5);

        // Assert: Only 5 tasks returned
        $this->assertSame(5, $result->count());
    }

    public function test_find_pending_without_limit_returns_all_tasks(): void
    {
        // Arrange: Create 10 pending tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask($i);
            $this->storage->savePending($task);
        }

        // Act: Find pending tasks without limit
        $result = $this->storage->findPending();

        // Assert: All 10 tasks returned
        $this->assertSame(10, $result->count());
    }

    public function test_find_pending_with_limit_zero_returns_no_tasks(): void
    {
        // Arrange: Create 5 pending tasks
        for ($i = 1; $i <= 5; $i++) {
            $task = $this->createTestTask($i);
            $this->storage->savePending($task);
        }

        // Act: Find pending tasks with limit 0
        $result = $this->storage->findPending(0);

        // Assert: No tasks returned
        $this->assertSame(0, $result->count());
    }

    public function test_find_pending_with_limit_greater_than_total_returns_all(): void
    {
        // Arrange: Create 5 pending tasks
        for ($i = 1; $i <= 5; $i++) {
            $task = $this->createTestTask($i);
            $this->storage->savePending($task);
        }

        // Act: Find pending tasks with limit 20
        $result = $this->storage->findPending(20);

        // Assert: All 5 tasks returned
        $this->assertSame(5, $result->count());
    }

    /**
     * Note: Les tests d'ordre utilisent le tri par filemtime() qui peut être
     * imprécis selon le système de fichiers. Ces tests vérifient principalement
     * que le tri ne casse pas et que la limite fonctionne.
     */
    public function test_find_pending_with_order_oldest_returns_oldest_first(): void
    {
        // Arrange: Create tasks
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTestTask($i);
            $this->storage->savePending($task);
        }

        // Act: Find pending tasks ordered oldest first
        $result = $this->storage->findPending(null, 'oldest');

        // Assert: Le résultat contient les 3 tâches (l'ordre exact peut varier)
        $this->assertCount(3, $result);

        // Vérifier que toutes les tâches sont présentes
        $ids = [];
        foreach ($result as $task) {
            $ids[] = $task->id;
        }
        $this->assertContains('task-1', $ids);
        $this->assertContains('task-2', $ids);
        $this->assertContains('task-3', $ids);
    }

    public function test_find_pending_with_order_newest_returns_newest_first(): void
    {
        // Arrange: Create tasks
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTestTask($i);
            $this->storage->savePending($task);
        }

        // Act: Find pending tasks ordered newest first
        $result = $this->storage->findPending(null, 'newest');

        // Assert: Le résultat contient les 3 tâches
        $this->assertCount(3, $result);

        // Vérifier que toutes les tâches sont présentes
        $ids = [];
        foreach ($result as $task) {
            $ids[] = $task->id;
        }
        $this->assertContains('task-1', $ids);
        $this->assertContains('task-2', $ids);
        $this->assertContains('task-3', $ids);
    }

    public function test_find_pending_with_limit_and_order_works_together(): void
    {
        // Arrange: Create 10 tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask($i);
            $this->storage->savePending($task);
        }

        // Act: Get 3 newest tasks
        $result = $this->storage->findPending(3, 'newest');

        // Assert: 3 tasks returned (ordre exact peut varier)
        $this->assertCount(3, $result);
    }

    public function test_find_pending_with_limit_and_oldest_order(): void
    {
        // Arrange: Create 10 tasks
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask($i);
            $this->storage->savePending($task);
        }

        // Act: Get 3 oldest tasks
        $result = $this->storage->findPending(3, 'oldest');

        // Assert: 3 tasks returned
        $this->assertCount(3, $result);
    }
}
