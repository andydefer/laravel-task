<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Workflows;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class FailedTaskRetryTest extends IntegrationTestCase
{
    private TaskStorage $storage;

    private TaskRunner $runner;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/task_storage_'.uniqid();

        $this->storage = new TaskStorage($this->storagePath);
        $logger = $this->app->make(Logger::class);
        $validator = $this->app->make(TaskValidator::class);
        $this->runner = new TaskRunner($this->storage, $logger, $validator);
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

        $files = glob($path.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $this->removeDirectory($file);
            }
        }

        rmdir($path);
    }

    private function createTaskPayload(?array $customData = null): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection;

        if ($customData !== null) {
            $payloadCollection->add(StrictDataObject::from($customData));
        } else {
            $payloadCollection->add(StrictDataObject::from([
                'test_data' => 'retry_test',
            ]));
        }

        return new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );
    }

    private function createFailingTask(
        string $id,
        int $attempts = 0,
        int $maxAttempts = 3,
        ?string $endAt = null
    ): TaskRecord {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: $id,
            signature: 'failing',
            class: FailingTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: $endAt ?? date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: $attempts,
            maxAttempts: $maxAttempts,
        );
    }

    private function createSuccessfulTask(): TaskRecord
    {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: 'success-no-retry',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
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

    public function test_failed_task_increments_attempts(): void
    {
        // Arrange: Create a failing task with 0 attempts
        $task = $this->createFailingTask('retry-test-1', attempts: 0, maxAttempts: 3);
        $this->storage->savePending($task);

        // Act: Execute the failing task
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert: Task failed and attempts were incremented
        $this->assertFalse($result);
        $this->assertSame(1, $pending->count());

        $updatedTask = $pending->first();
        $this->assertSame(1, $updatedTask->attempts);
        $this->assertNotNull($updatedTask->lastError);
    }

    public function test_failed_task_increments_attempts_multiple_times(): void
    {
        // Arrange: Create a failing task with 0 attempts
        $task = $this->createFailingTask('retry-test-2', attempts: 0, maxAttempts: 3);
        $this->storage->savePending($task);

        // Act: First execution attempt
        $this->runner->runTask($task);
        $pending = $this->storage->findPending();
        $updatedTask = $pending->first();

        // Act: Second execution attempt
        $this->runner->runTask($updatedTask);
        $pending = $this->storage->findPending();

        // Assert: Attempts incremented to 2
        $this->assertSame(1, $pending->count());
        $finalTask = $pending->first();
        $this->assertSame(2, $finalTask->attempts);
    }

    public function test_task_is_archived_after_max_attempts(): void
    {
        // Arrange: Create a failing task with 2 attempts (1 more to reach max)
        $task = $this->createFailingTask('max-retry-test-1', attempts: 2, maxAttempts: 3);
        $this->storage->savePending($task);

        // Act: Execute the task (3rd attempt)
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert: Task failed and was archived (no retry left)
        $this->assertFalse($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_task_with_no_retry_possible_is_archived_immediately(): void
    {
        // Arrange: Create a failing task with maxAttempts = 1 (no retry)
        $task = $this->createFailingTask('no-retry-test', attempts: 0, maxAttempts: 1);
        $this->storage->savePending($task);

        // Act: Execute the task
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert: Task failed and was archived immediately
        $this->assertFalse($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_successful_task_does_not_retry(): void
    {
        // Arrange: Create a successful task
        $task = $this->createSuccessfulTask();
        $this->storage->savePending($task);

        // Act: Execute the task
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert: Task succeeded and was archived (no retry needed)
        $this->assertTrue($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_failed_task_preserves_payload_after_retry(): void
    {
        // Arrange: Create a failing task with custom payload
        $customPayload = $this->createTaskPayload([
            'custom_data' => 123,
            'test_value' => 'test_value',
        ]);

        $task = new TaskRecord(
            id: 'payload-test',
            signature: 'failing',
            class: FailingTask::class,
            payload: $customPayload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        // Act: Execute the failing task
        $this->runner->runTask($task);
        $pending = $this->storage->findPending();
        $updatedTask = $pending->first();

        // Assert: Payload was preserved after retry
        $this->assertSame($task->payload->type, $updatedTask->payload->type);
        $this->assertSame($task->payload->payload->count(), $updatedTask->payload->payload->count());
    }

    public function test_expired_task_does_not_retry(): void
    {
        // Arrange: Create an expired failing task
        $task = $this->createFailingTask(
            id: 'expired-retry',
            attempts: 0,
            maxAttempts: 3,
            endAt: date('c', strtotime('-1 day'))
        );
        $this->storage->savePending($task);

        // Act: Execute the expired task
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert: Task not executed and was archived
        $this->assertFalse($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_task_retry_respects_max_attempts_boundary(): void
    {
        // Arrange: Create a failing task with maxAttempts = 5
        $maxAttempts = 5;
        $task = $this->createFailingTask('boundary-test', attempts: 0, maxAttempts: $maxAttempts);
        $this->storage->savePending($task);

        // Act: Execute the task up to max attempts
        $currentTask = $task;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->runner->runTask($currentTask);
            $pending = $this->storage->findPending();
            if ($pending->isNotEmpty()) {
                $currentTask = $pending->first();
            }
        }

        // Assert: Task is archived after reaching max attempts
        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_task_retry_stores_error_message_each_time(): void
    {
        // Arrange: Create a failing task
        $task = $this->createFailingTask('error-message-test', attempts: 0, maxAttempts: 3);
        $this->storage->savePending($task);

        // Act: First execution attempt
        $this->runner->runTask($task);
        $pending = $this->storage->findPending();
        $updatedTask = $pending->first();

        // Assert: First error message is stored
        $this->assertNotNull($updatedTask->lastError, 'First error message should not be null');
        $firstError = $updatedTask->lastError;
        $this->assertIsString($firstError);
        $this->assertStringContainsString('Test exception', $firstError);

        // Act: Second execution attempt
        $this->runner->runTask($updatedTask);
        $pending = $this->storage->findPending();
        $finalTask = $pending->first();

        // Assert: Second error message is stored (should be the same message but that's fine)
        $this->assertNotNull($finalTask->lastError, 'Second error message should not be null');
        $secondError = $finalTask->lastError;
        $this->assertIsString($secondError);
        $this->assertStringContainsString('Test exception', $secondError);

        // The error message should be present (it's fine if it's the same string)
        // Both attempts should have an error message stored
        $this->assertNotEmpty($firstError);
        $this->assertNotEmpty($secondError);
    }
}
