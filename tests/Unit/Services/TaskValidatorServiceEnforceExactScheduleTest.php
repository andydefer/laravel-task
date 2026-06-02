<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\UnitTestCase;
use Carbon\Carbon;
use PHPUnit\Framework\MockObject\Stub;

final class TaskValidatorServiceEnforceExactScheduleTest extends UnitTestCase
{
    private TaskValidatorService $validator;

    private TaskConfig&Stub $config;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock config with grace period enabled
        $this->config = $this->createStub(TaskConfig::class);
        $this->config->method('gracePeriodEnabled')->willReturn(true);
        $this->config->method('gracePeriodSeconds')->willReturn(86400); // 24 hours

        $this->validator = new TaskValidatorService($this->config);

        // Freeze time in UTC to avoid timezone issues
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection;
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'sample',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );
    }

    private function createTestTask(
        string $startAt,
        ?string $endAt = null,
        bool $enforceExactSchedule = false,
        int $delaySeconds = 0
    ): TaskRecord {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: '123',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: $delaySeconds,
            attempts: 0,
            maxAttempts: 3,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    public function test_task_with_enforce_exact_schedule_executable_when_within_window(): void
    {
        // Arrange: Create a task with exact schedule enforcement within valid window
        $startAt = '2026-05-24 12:00:00';
        $endAt = '2026-05-24 12:30:00';

        $task = $this->createTestTask(
            startAt: $startAt,
            endAt: $endAt,
            enforceExactSchedule: true,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should be executable (current time 12:15 is within window)
        $this->assertTrue($result);
    }

    public function test_task_with_enforce_exact_schedule_not_executable_when_expired(): void
    {
        // Arrange: Create an expired task with exact schedule enforcement
        $task = $this->createTestTask(
            startAt: '2026-05-24 10:00:00',
            endAt: '2026-05-24 10:10:00',
            enforceExactSchedule: true,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should not be executable (current time 12:15 is after end)
        $this->assertFalse($result);
    }

    public function test_task_without_enforce_exact_schedule_benefits_from_grace_period(): void
    {
        // Arrange: Create an expired task without exact schedule enforcement
        $task = $this->createTestTask(
            startAt: '2026-05-24 12:00:00',
            endAt: '2026-05-24 12:10:00',  // Already passed (now is 12:15)
            enforceExactSchedule: false,
        );

        // Act: Check if task can run (benefits from grace period)
        $result = $this->validator->canRunTask($task);

        // Assert: Task should be executable due to grace period
        $this->assertTrue($result);
    }

    public function test_is_task_expired_with_enforce_exact_schedule(): void
    {
        // Arrange: Create an expired task with exact schedule enforcement
        $task = $this->createTestTask(
            startAt: '2026-05-24 10:00:00',
            endAt: '2026-05-24 10:10:00',
            enforceExactSchedule: true,
        );

        // Act: Check if task is expired
        $isExpired = $this->validator->isTaskExpired($task);

        // Assert: Task should be considered expired
        $this->assertTrue($isExpired);
    }

    public function test_is_task_expired_without_enforce_exact_schedule(): void
    {
        // Arrange: Create an expired task without exact schedule enforcement
        $task = $this->createTestTask(
            startAt: '2026-05-24 12:00:00',
            endAt: '2026-05-24 12:10:00',
            enforceExactSchedule: false,
        );

        // Act: Check if task is expired
        $isExpired = $this->validator->isTaskExpired($task);

        // Assert: Task should not be considered expired due to grace period
        $this->assertFalse($isExpired);
    }

    public function test_is_unique_task_with_grace_period_false_when_enforce_exact_schedule(): void
    {
        // Arrange: Create a task with exact schedule enforcement
        $task = $this->createTestTask(
            startAt: '2026-05-24 12:00:00',
            endAt: '2026-05-24 12:10:00',
            enforceExactSchedule: true,
        );

        // Act: Check if task qualifies for grace period
        $isUnique = $this->validator->isUniqueTaskWithGracePeriod($task);

        // Assert: Task should NOT qualify for grace period
        $this->assertFalse($isUnique);
    }

    public function test_is_unique_task_with_grace_period_true_when_no_enforce_exact_schedule(): void
    {
        // Arrange: Create a task without exact schedule enforcement
        $task = $this->createTestTask(
            startAt: '2026-05-24 12:00:00',
            endAt: '2026-05-24 12:10:00',
            enforceExactSchedule: false,
        );

        // Act: Check if task qualifies for grace period
        $isUnique = $this->validator->isUniqueTaskWithGracePeriod($task);

        // Assert: Task should qualify for grace period
        $this->assertTrue($isUnique);
    }

    public function test_grace_period_disabled_via_config(): void
    {
        // Arrange: Create mock config with grace period disabled
        $config = $this->createStub(TaskConfig::class);
        $config->method('gracePeriodEnabled')->willReturn(false);
        $config->method('gracePeriodSeconds')->willReturn(86400);

        $validator = new TaskValidatorService($config);

        $task = $this->createTestTask(
            startAt: '2026-05-24 12:00:00',
            endAt: '2026-05-24 12:10:00',
            enforceExactSchedule: false,
        );

        // Act: Check if task can run (should not benefit from grace period)
        $result = $validator->canRunTask($task);

        // Assert: Task should NOT be executable when grace period is disabled
        $this->assertFalse($result);
    }

    public function test_grace_period_seconds_customized_via_config(): void
    {
        // Arrange: Create mock config with custom grace period (1 hour = 3600 seconds)
        $config = $this->createStub(TaskConfig::class);
        $config->method('gracePeriodEnabled')->willReturn(true);
        $config->method('gracePeriodSeconds')->willReturn(3600);

        $validator = new TaskValidatorService($config);

        // Task ended at 12:10, now is 12:15 (5 minutes = 300 seconds)
        // With 3600 seconds grace period, task should still be executable
        $task = $this->createTestTask(
            startAt: '2026-05-24 12:00:00',
            endAt: '2026-05-24 12:10:00',
            enforceExactSchedule: false,
        );

        // Act: Check if task can run
        $result = $validator->canRunTask($task);

        // Assert: Task should be executable within custom grace period
        $this->assertTrue($result);
    }
}
