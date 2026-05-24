<?php

// tests/Integration/Services/TaskRunnerGracePeriodTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Carbon\Carbon;

final class TaskRunnerGracePeriodTest extends IntegrationTestCase
{
    private TaskStorage $storage;
    private TaskRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('task.grace_period.enabled', true);
        config()->set('task.grace_period.seconds', 86400); // 24h

        // Figer le temps à 12h15 (5 minutes après la fin de la tâche)
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));

        $this->storage = $this->app->make(TaskStorage::class);
        $logger = $this->app->make(Logger::class);
        $validator = $this->app->make(TaskValidator::class);
        $this->runner = new TaskRunner($this->storage, $logger, $validator);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createExpiredTask(bool $enforceExactSchedule = false): TaskRecord
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        return new TaskRecord(
            id: 'expired-task',
            signature: 'test-task',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    public function test_expired_unique_task_is_executed_during_grace_period(): void
    {
        $task = $this->createExpiredTask(false);
        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertTrue($result, 'La tâche expirée devrait être exécutée pendant la période de grâce');

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count(), 'La tâche devrait être archivée après exécution');
    }

    public function test_expired_unique_task_archived_if_grace_period_expired(): void
    {
        // Utiliser enforceExactSchedule = true pour désactiver la période de grâce
        $task = $this->createExpiredTask(true);
        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result, 'La tâche ne devrait pas être exécutée car elle est expirée et enforceExactSchedule est true');

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count(), 'La tâche devrait être archivée');
    }

    public function test_grace_period_tracking_logs_are_created(): void
    {
        $task = $this->createExpiredTask(false);
        $this->storage->savePending($task);

        $this->runner->runTask($task);

        $graceFilePath = storage_path('tasks/grace_period/expired-task.json');
        $this->assertFileExists($graceFilePath);

        $content = file_get_contents($graceFilePath);
        $data = json_decode($content, true);

        // Vérifier les clés (toArray() convertit en snake_case)
        $this->assertArrayHasKey('task_id', $data, 'La clé task_id devrait exister');
        $this->assertSame('expired-task', $data['task_id']);
        $this->assertArrayHasKey('signature', $data);
        $this->assertSame('test-task', $data['signature']);
        $this->assertArrayHasKey('delay_seconds', $data);
        $this->assertGreaterThan(0, $data['delay_seconds']);

        unlink($graceFilePath);
        $graceDir = dirname($graceFilePath);
        if (is_dir($graceDir) && count(scandir($graceDir)) === 2) {
            rmdir($graceDir);
        }
    }

    public function test_recurring_task_not_affected_by_grace_period(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        $task = new TaskRecord(
            id: 'recurring-task',
            signature: 'recurring-test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::DEFER,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 300,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result, 'Les tâches récurrentes ne bénéficient pas de la période de grâce');
    }

    public function test_unique_task_outside_grace_period_is_not_executed(): void
    {
        // Utiliser enforceExactSchedule = true pour désactiver la période de grâce
        $task = $this->createExpiredTask(true);
        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result, 'La tâche ne devrait pas être exécutée car elle est expirée');
    }
}
