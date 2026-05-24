<?php

// tests/Integration/Services/TaskValidatorTest.php

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

final class TaskValidatorTest extends IntegrationTestCase
{
    private TaskValidator $validator;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('task.grace_period.enabled', true);
        config()->set('task.grace_period.seconds', 86400);

        // Fixer le temps simulé en UTC
        $this->now = Carbon::create(2026, 5, 24, 12, 15, 0, 'UTC');
        Carbon::setTestNow($this->now);

        $this->validator = new TaskValidator();
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
            payload: new MixedPayloadCollection(),
        );

        return new TaskRecord(
            id: '123',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: $status,
            createdAt: $this->now->toIso8601String(),
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: $delaySeconds,
            attempts: $attempts,
            maxAttempts: 3,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    private function getRelativeDate(string $modifier): string
    {
        return $this->now->copy()->modify($modifier)->toIso8601String();
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
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
        );

        $result = $this->validator->canRunTask($task);
        $this->assertTrue($result);
    }

    public function test_can_run_task_returns_false_for_task_not_started_yet(): void
    {
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('+1 hour'),
            endAt: $this->getRelativeDate('+2 hours'),
        );

        $result = $this->validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_for_completed_task(): void
    {
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            status: TaskStatus::SUCCESS,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_when_max_attempts_reached(): void
    {
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            attempts: 3,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_for_expired_task_without_grace_period(): void
    {
        config()->set('task.grace_period.enabled', false);

        // Créer un nouveau validator après le changement de config
        $validator = new TaskValidator();

        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);

        config()->set('task.grace_period.enabled', true);
    }

    public function test_can_run_task_returns_true_for_expired_task_with_grace_period(): void
    {
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertTrue($result);
    }

    public function test_is_task_expired_returns_true_for_expired_task(): void
    {
        // Désactiver temporairement la période de grâce pour ce test
        config()->set('task.grace_period.enabled', false);

        // Créer un nouveau validator après le changement de config
        $validator = new TaskValidator();

        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
        );

        $result = $validator->isTaskExpired($task);
        $this->assertTrue($result);

        // Réactiver la période de grâce
        config()->set('task.grace_period.enabled', true);
    }

    public function test_is_task_expired_returns_false_for_non_expired_task(): void
    {
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
        );

        $result = $this->validator->isTaskExpired($task);
        $this->assertFalse($result);
    }

    public function test_can_run_task_with_enforce_exact_schedule_not_executable_when_expired(): void
    {
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
            enforceExactSchedule: true,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_can_run_task_with_enforce_exact_schedule_executable_when_within_window(): void
    {
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            enforceExactSchedule: true,
        );

        $result = $this->validator->canRunTask($task);
        $this->assertTrue($result);
    }

    public function test_is_task_expired_with_enforce_exact_schedule_returns_true(): void
    {
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            enforceExactSchedule: true,
        );

        $result = $this->validator->isTaskExpired($task);
        $this->assertTrue($result);
    }

    public function test_is_unique_task_with_grace_period_true(): void
    {
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 0,
        );

        $result = $this->validator->isUniqueTaskWithGracePeriod($task);
        $this->assertTrue($result);
    }

    public function test_is_unique_task_with_grace_period_false_when_enforce_exact_schedule(): void
    {
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 0,
            enforceExactSchedule: true,
        );

        $result = $this->validator->isUniqueTaskWithGracePeriod($task);
        $this->assertFalse($result);
    }

    public function test_is_unique_task_with_grace_period_false_for_recurring_task(): void
    {
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 300,
        );

        $result = $this->validator->isUniqueTaskWithGracePeriod($task);
        $this->assertFalse($result);
    }

    public function test_get_grace_period_delay(): void
    {
        // Créer une tâche dont endAt est 5 minutes dans le passé
        $endAt = $this->now->copy()->subMinutes(5);

        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $endAt->toIso8601String(),
            delaySeconds: 0,
        );

        $delay = $this->validator->getGracePeriodDelay($task);

        // Le délai devrait être d'au moins 300 secondes (5 minutes)
        $this->assertGreaterThanOrEqual(300, $delay);
    }
}
