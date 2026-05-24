<?php

// tests/Integration/Services/TaskValidatorEnforceExactScheduleTest.php

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

final class TaskValidatorEnforceExactScheduleTest extends IntegrationTestCase
{
    private TaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('task.grace_period.enabled', true);
        config()->set('task.grace_period.seconds', 86400);

        $this->validator = new TaskValidator();

        // Fixer le temps en UTC pour éviter les problèmes de timezone
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createTestTask(
        string $startAt,
        ?string $endAt = null,
        bool $enforceExactSchedule = false,
        int $delaySeconds = 0
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

        // Créer la tâche avec des dates explicites
        $startAt = '2026-05-24 12:00:00';
        $endAt = '2026-05-24 12:30:00';

        $task = $this->createTestTask(
            startAt: $startAt,
            endAt: $endAt,
            enforceExactSchedule: true,
        );

        // Récupérer les timestamps
        $now = time();
        $startAtTimestamp = strtotime($task->startAt);
        $endAtTimestamp = strtotime($task->endAt);

        // Afficher les informations de debug



        $result = $this->validator->canRunTask($task);


        $this->assertTrue($result);
    }

    public function test_task_with_enforce_exact_schedule_not_executable_when_expired(): void
    {

        $task = $this->createTestTask(
            startAt: '2026-05-24 10:00:00',
            endAt: '2026-05-24 10:10:00',
            enforceExactSchedule: true,
        );

        $now = time();
        $startAtTimestamp = strtotime($task->startAt);
        $endAtTimestamp = strtotime($task->endAt);


        $result = $this->validator->canRunTask($task);

        $this->assertFalse($result);
    }

    public function test_task_without_enforce_exact_schedule_benefits_from_grace_period(): void
    {

        $task = $this->createTestTask(
            startAt: '2026-05-24 12:00:00',
            endAt: '2026-05-24 12:10:00',  // Déjà passé (12:15)
            enforceExactSchedule: false,
        );

        $now = time();
        $endAtTimestamp = strtotime($task->endAt);
        $graceEnd = $endAtTimestamp + 86400;


        $result = $this->validator->canRunTask($task);

        $this->assertTrue($result);
    }

    // Garder les autres tests sans debug pour ne pas surcharger
    public function test_is_task_expired_with_enforce_exact_schedule(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24 10:00:00',
            endAt: '2026-05-24 10:10:00',
            enforceExactSchedule: true,
        );

        $isExpired = $this->validator->isTaskExpired($task);
        $this->assertTrue($isExpired);
    }

    public function test_is_task_expired_without_enforce_exact_schedule(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24 12:00:00',
            endAt: '2026-05-24 12:10:00',
            enforceExactSchedule: false,
        );

        $isExpired = $this->validator->isTaskExpired($task);
        $this->assertFalse($isExpired);
    }

    public function test_is_unique_task_with_grace_period_false_when_enforce_exact_schedule(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24 12:00:00',
            endAt: '2026-05-24 12:10:00',
            enforceExactSchedule: true,
        );

        $isUnique = $this->validator->isUniqueTaskWithGracePeriod($task);
        $this->assertFalse($isUnique);
    }

    public function test_is_unique_task_with_grace_period_true_when_no_enforce_exact_schedule(): void
    {
        $task = $this->createTestTask(
            startAt: '2026-05-24 12:00:00',
            endAt: '2026-05-24 12:10:00',
            enforceExactSchedule: false,
        );

        $isUnique = $this->validator->isUniqueTaskWithGracePeriod($task);
        $this->assertTrue($isUnique);
    }
}
