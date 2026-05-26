<?php

// tests/Integration/Workflows/FailedTaskRetryTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Workflows;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->app->make(TaskStorage::class);
        $logger = $this->app->make(Logger::class);
        $validator = $this->app->make(TaskValidator::class);
        $this->runner = new TaskRunner($this->storage, $logger, $validator);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_failed_task_increments_attempts(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: 'retry-test-1',
            signature: 'failing',
            class: FailingTask::class,
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

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());

        $updatedTask = $pending->firstItem();
        $this->assertSame(1, $updatedTask->attempts);
        $this->assertNotNull($updatedTask->lastError);
    }

    public function test_failed_task_increments_attempts_multiple_times(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: 'retry-test-2',
            signature: 'failing',
            class: FailingTask::class,
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

        $this->storage->savePending($task);

        // Premier run
        $this->runner->runTask($task);

        // Récupérer la tâche mise à jour
        $pending = $this->storage->findPending();
        $updatedTask = $pending->firstItem();

        // Deuxième run avec la tâche mise à jour
        $this->runner->runTask($updatedTask);

        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());

        $finalTask = $pending->firstItem();
        $this->assertSame(2, $finalTask->attempts);
    }

    public function test_task_is_archived_after_max_attempts(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: 'max-retry-test-1',
            signature: 'failing',
            class: FailingTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 2,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_task_with_no_retry_possible_is_archived_immediately(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: 'no-retry-test',
            signature: 'failing',
            class: FailingTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 1,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_successful_task_does_not_retry(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
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

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertTrue($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_failed_task_preserves_payload_after_retry(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payloadCollection->add('custom_data', 123, 'test_value');

        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new TaskRecord(
            id: 'payload-test',
            signature: 'failing',
            class: FailingTask::class,
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

        $this->storage->savePending($task);

        $this->runner->runTask($task);

        $pending = $this->storage->findPending();
        $updatedTask = $pending->firstItem();

        $this->assertSame($task->payload->type, $updatedTask->payload->type);
        $this->assertSame($task->payload->payload->count(), $updatedTask->payload->payload->count());
    }

    public function test_expired_task_does_not_retry(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: 'expired-retry',
            signature: 'failing',
            class: FailingTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c', strtotime('-2 days')),
            startAt: date('c', strtotime('-2 days')),
            endAt: date('c', strtotime('-1 day')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_task_retry_respects_max_attempts_boundary(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $maxAttempts = 5;

        $task = new TaskRecord(
            id: 'boundary-test',
            signature: 'failing',
            class: FailingTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: $maxAttempts,
        );

        $this->storage->savePending($task);

        $currentTask = $task;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->runner->runTask($currentTask);
            $pending = $this->storage->findPending();
            if ($pending->isNotEmpty()) {
                $currentTask = $pending->firstItem();
            }
        }

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_task_retry_stores_error_message_each_time(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: 'error-message-test',
            signature: 'failing',
            class: FailingTask::class,
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

        $this->storage->savePending($task);

        $this->runner->runTask($task);

        $pending = $this->storage->findPending();
        $updatedTask = $pending->firstItem();
        $this->assertNotNull($updatedTask->lastError);

        $this->runner->runTask($updatedTask);

        $pending = $this->storage->findPending();
        $finalTask = $pending->firstItem();
        $this->assertNotNull($finalTask->lastError);
    }
}
