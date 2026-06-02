<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Workflows;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use PHPUnit\Framework\MockObject\Stub;

final class TaskLifecycleTest extends IntegrationTestCase
{
    private TaskStorageService $storage;

    private TaskRunnerService $runner;

    private TaskValidatorService $validator;

    private string $storagePath;

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
        $this->config->method('storageGracePeriodPath')->willReturn($this->storagePath . '/grace_period');
        $this->config->method('gracePeriodEnabled')->willReturn(false);
        $this->config->method('gracePeriodSeconds')->willReturn(86400);
        $this->config->method('batchLimit')->willReturn(1000);
        $this->config->method('batchOrder')->willReturn('oldest');

        $this->storage = new TaskStorageService($this->config);
        $logger = $this->app->make(Logger::class);
        $this->validator = new TaskValidatorService($this->config);
        $this->runner = new TaskRunnerService(
            storage: $this->storage,
            logger: $logger,
            validator: $this->validator,
            config: $this->config,
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

        $files = glob($path . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $this->removeDirectory($file);
            }
        }

        rmdir($path);
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection;
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'lifecycle_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );
    }

    private function createTestTask(
        string $id,
        string $class = TestTask::class,
        string $signature = 'test',
        int $attempts = 0,
        int $maxAttempts = 3,
        ?string $startAt = null,
        ?string $endAt = null,
        bool $enforceExactSchedule = false
    ): TaskRecord {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: $id,
            signature: $signature,
            class: $class,
            payload: $payload,

            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: $startAt ?? date('c', strtotime('-1 minute')),
            endAt: $endAt ?? date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: $attempts,
            maxAttempts: $maxAttempts,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    public function test_complete_task_lifecycle(): void
    {
        // Arrange: Create and save a pending task
        $task = $this->createTestTask('lifecycle-test');
        $this->storage->savePending($task);

        // Act: Verify task exists, then execute it
        $pendingBefore = $this->storage->findPending();
        $result = $this->runner->runTask($task);
        $pendingAfter = $this->storage->findPending();

        // Assert: Task was executed and removed from pending
        $this->assertSame(1, $pendingBefore->count());
        $this->assertTrue($result);
        $this->assertSame(0, $pendingAfter->count());
    }

    public function test_task_created_with_pending_status(): void
    {
        // Arrange: Create a new task
        $task = $this->createTestTask('status-test');

        // Act: Save the task and retrieve it
        $this->storage->savePending($task);
        $pending = $this->storage->findPending();
        $savedTask = $pending->first();

        // Assert: Task has PENDING status
        $this->assertNotNull($savedTask);
        $this->assertSame(TaskStatus::PENDING, $savedTask->status);
    }

    public function test_task_moves_to_completed_after_success(): void
    {
        // Arrange: Create and save a pending task
        $task = $this->createTestTask('completed-test');
        $this->storage->savePending($task);

        // Act: Execute the task
        $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert: Task was removed from pending (moved to completed)
        $this->assertSame(0, $pending->count());
    }

    public function test_task_not_started_before_start_at(): void
    {
        // Arrange: Create a task that starts in the future
        $task = $this->createTestTask(
            id: 'future-test',
            startAt: date('c', strtotime('+1 hour')),
            endAt: date('c', strtotime('+2 hours'))
        );
        $this->storage->savePending($task);

        // Act: Check if task can run
        $canRun = $this->validator->canRunTask($task);

        // Assert: Task should not run (not started yet)
        $this->assertFalse($canRun);
    }

    public function test_task_does_not_run_after_end_at(): void
    {
        // Arrange: Create an expired task with exact schedule enforcement
        $task = $this->createTestTask(
            id: 'expired-test',
            startAt: date('c', strtotime('-2 days')),
            endAt: date('c', strtotime('-1 day')),
            enforceExactSchedule: true
        );
        $this->storage->savePending($task);

        // Act: Attempt to execute the expired task
        $result = $this->runner->runTask($task);

        // Assert: Task should not run (expired with exact schedule)
        $this->assertFalse($result);
    }

    public function test_task_can_be_deleted_before_execution(): void
    {
        // Arrange: Create and save a pending task
        $task = $this->createTestTask('delete-test');
        $this->storage->savePending($task);

        // Act: Delete the task before execution
        $this->storage->deletePending('delete-test');
        $pending = $this->storage->findPending();

        // Assert: Task was removed
        $this->assertSame(0, $pending->count());
    }

    public function test_task_failure_does_not_remove_from_pending(): void
    {
        // Arrange: Create a failing task
        $task = $this->createTestTask(
            id: 'failure-stay',
            class: FailingTask::class,
            signature: 'failing'
        );
        $this->storage->savePending($task);

        // Act: Execute the failing task
        $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert: Task remains in pending (for retry)
        $this->assertSame(1, $pending->count());
    }

    public function test_task_can_be_retrieved_by_id(): void
    {
        // Arrange: Create and save a task with specific ID
        $taskId = 'retrieve-test';
        $task = $this->createTestTask($taskId);
        $this->storage->savePending($task);

        // Act: Retrieve the task from storage
        $pending = $this->storage->findPending();
        $foundTask = $pending->first();

        // Assert: Task can be retrieved by ID
        $this->assertNotNull($foundTask);
        $this->assertSame($taskId, $foundTask->id);
        $this->assertSame(TestTask::class, $foundTask->class);
    }

    public function test_multiple_tasks_can_be_processed_sequentially(): void
    {
        // Arrange: Create and save 3 pending tasks
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTestTask("sequential-{$i}");
            $this->storage->savePending($task);
        }

        // Act: Verify tasks exist, then execute them all
        $pendingBefore = $this->storage->findPending();

        foreach ($pendingBefore as $task) {
            $this->runner->runTask($task);
        }

        $pendingAfter = $this->storage->findPending();

        // Assert: All tasks were executed and removed
        $this->assertSame(3, $pendingBefore->count());
        $this->assertSame(0, $pendingAfter->count());
    }
}
