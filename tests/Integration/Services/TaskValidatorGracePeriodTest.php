<?php

// tests/Integration/Services/TaskValidatorGracePeriodTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Carbon\Carbon;

final class TaskValidatorGracePeriodTest extends IntegrationTestCase
{
    private TaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('task.grace_period.enabled', true);
        config()->set('task.grace_period.seconds', 86400);

        $this->validator = new TaskValidator;

        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createTestTask(
        string $startAt,
        ?string $endAt = null,
        int $delaySeconds = 0,
        TaskStatus $status = TaskStatus::PENDING,
        int $attempts = 0,
        bool $enforceExactSchedule = false
    ): TaskRecord {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        return new TaskRecord(
            id: '123',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: $status,
            createdAt: date('c'),
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: $delaySeconds,
            attempts: $attempts,
            maxAttempts: 3,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    public function test_unique_task_expired_but_within_grace_period_is_executable(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertTrue($result);
    }

    public function test_unique_task_expired_and_outside_grace_period_is_not_executable(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-23T12:00:00Z',
            endAt: '2026-05-23T12:10:00Z',
            delaySeconds: 0,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_recurring_task_does_not_get_grace_period(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 300,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_unique_task_within_time_window_is_executable(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:30:00Z',
            delaySeconds: 0,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertTrue($result);
    }

    public function test_task_not_started_yet_is_not_executable(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24T13:00:00Z',
            endAt: '2026-05-24T14:00:00Z',
            delaySeconds: 0,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_task_with_max_attempts_reached_is_not_executable(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:30:00Z',
            delaySeconds: 0,
            attempts: 3,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_task_with_non_pending_status_is_not_executable(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:30:00Z',
            delaySeconds: 0,
            status: TaskStatus::SUCCESS,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_grace_period_disabled(): void
    {
        config()->set('task.grace_period.enabled', false);

        $validator = new TaskValidator;

        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);

        // Restaurer la configuration
        config()->set('task.grace_period.enabled', true);
    }

    public function test_is_task_expired_with_grace_period(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        $isExpired = $this->validator->isTaskExpired($task);
        $this->assertFalse($isExpired);
    }

    public function test_is_task_expired_outside_grace_period(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-23T12:00:00Z',
            endAt: '2026-05-23T12:10:00Z',
            delaySeconds: 0,
        );

        $isExpired = $this->validator->isTaskExpired($task);
        $this->assertTrue($isExpired);
    }

    public function test_get_grace_period_delay(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        $delay = $this->validator->getGracePeriodDelay($task);
        $this->assertGreaterThanOrEqual(300, $delay);
    }

    public function test_is_unique_task_with_grace_period(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        $isUnique = $this->validator->isUniqueTaskWithGracePeriod($task);
        $this->assertTrue($isUnique);
    }

    public function test_recurring_task_is_not_unique_with_grace_period(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: null,
            delaySeconds: 300,
        );

        $isUnique = $this->validator->isUniqueTaskWithGracePeriod($task);
        $this->assertFalse($isUnique);
    }
}
