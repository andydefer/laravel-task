<?php

// tests/Unit/Services/TaskValidatorTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\UnitTestCase;

final class TaskValidatorTest extends UnitTestCase
{
    private TaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new TaskValidator();
    }

    public function test_validate_task_class_returns_true_for_valid_class(): void
    {
        $result = $this->validator->validateTaskClass(TestTask::class);

        $this->assertTrue($result);
    }

    public function test_validate_task_class_returns_false_for_invalid_class(): void
    {
        $result = $this->validator->validateTaskClass('NonExistentClass');

        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_true_for_pending_task_with_valid_dates(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        $task = new TaskRecord(
            id: '123',
            signature: 'test',
            class: TestTask::class,
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

        $result = $this->validator->canRunTask($task);

        $this->assertTrue($result);
    }

    public function test_can_run_task_returns_false_for_task_not_started_yet(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        $task = new TaskRecord(
            id: '123',
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

        $result = $this->validator->canRunTask($task);

        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_for_completed_task(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        $task = new TaskRecord(
            id: '123',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::SUCCESS,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 hour')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $result = $this->validator->canRunTask($task);

        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_for_expired_task(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        $task = new TaskRecord(
            id: '123',
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
        );

        $result = $this->validator->canRunTask($task);

        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_when_max_attempts_reached(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        $task = new TaskRecord(
            id: '123',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 hour')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 3,
            maxAttempts: 3,
        );

        $result = $this->validator->canRunTask($task);

        $this->assertFalse($result);
    }

    public function test_is_task_expired_returns_true_for_expired_task(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        $task = new TaskRecord(
            id: '123',
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
        );

        $result = $this->validator->isTaskExpired($task);

        $this->assertTrue($result);
    }

    public function test_is_task_expired_returns_false_for_non_expired_task(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        $task = new TaskRecord(
            id: '123',
            signature: 'test',
            class: TestTask::class,
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

        $result = $this->validator->isTaskExpired($task);

        $this->assertFalse($result);
    }
}
