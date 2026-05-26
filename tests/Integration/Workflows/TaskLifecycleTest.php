<?php

// tests/Integration/Workflows/TaskLifecycleTest.php

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

final class TaskLifecycleTest extends IntegrationTestCase
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

    public function test_complete_task_lifecycle(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new TaskRecord(
            id: 'lifecycle-test',
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

        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());

        $result = $this->runner->runTask($task);
        $this->assertTrue($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_task_created_with_pending_status(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new TaskRecord(
            id: 'status-test',
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

        $pending = $this->storage->findPending();
        $savedTask = $pending->firstItem();

        $this->assertNotNull($savedTask);
        $this->assertSame(TaskStatus::PENDING, $savedTask->status);
    }

    public function test_task_moves_to_completed_after_success(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new TaskRecord(
            id: 'completed-test',
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

        $this->runner->runTask($task);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_task_not_started_before_start_at(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new TaskRecord(
            id: 'future-test',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('+1 hour')),
            endAt: date('c', strtotime('+2 hours')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $validator = $this->app->make(TaskValidator::class);
        $canRun = $validator->canRunTask($task);

        $this->assertFalse($canRun);
    }

    public function test_task_does_not_run_after_end_at(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new TaskRecord(
            id: 'expired-test',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c', strtotime('-2 days')),
            startAt: date('c', strtotime('-2 days')),
            endAt: date('c', strtotime('-1 day')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
            enforceExactSchedule: true,  // ← Force l'exécution exacte, pas de grâce
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_task_can_be_deleted_before_execution(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new TaskRecord(
            id: 'delete-test',
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

        $this->storage->deletePending('delete-test');

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_task_failure_does_not_remove_from_pending(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new TaskRecord(
            id: 'failure-stay',
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
        $this->assertSame(1, $pending->count());
    }

    public function test_task_can_be_retrieved_by_id(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $taskId = 'retrieve-test';
        $task = new TaskRecord(
            id: $taskId,
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

        $pending = $this->storage->findPending();
        $foundTask = $pending->firstItem();

        $this->assertNotNull($foundTask);
        $this->assertSame($taskId, $foundTask->id);
        $this->assertSame(TestTask::class, $foundTask->class);
    }

    public function test_multiple_tasks_can_be_processed_sequentially(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        for ($i = 1; $i <= 3; $i++) {
            $task = new TaskRecord(
                id: "sequential-{$i}",
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
        }

        $pending = $this->storage->findPending();
        $this->assertSame(3, $pending->count());

        foreach ($pending as $task) {
            $this->runner->runTask($task);
        }

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }
}
