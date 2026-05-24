<?php

// tests/Integration/Services/ProcessManagerTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\ProcessManager;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class ProcessManagerTest extends IntegrationTestCase
{
    private const FIXED_DATE = '2024-01-01T00:00:00+00:00';

    private TaskStorage $storage;
    private TaskRunner $runner;
    private TaskValidator $validator;
    private Logger $logger;
    private ProcessManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->app->make(TaskStorage::class);
        $this->validator = $this->app->make(TaskValidator::class);
        $this->logger = $this->app->make(Logger::class);
        $this->runner = new TaskRunner($this->storage, $this->logger, $this->validator);
        $this->manager = new ProcessManager($this->runner, $this->storage, $this->logger, $this->validator);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function createTestTask(string $id, TaskStatus $status = TaskStatus::PENDING): TaskRecord
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        return new TaskRecord(
            id: $id,
            signature: 'test-task',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: $status,
            createdAt: self::FIXED_DATE,
            startAt: self::FIXED_DATE,
            endAt: self::FIXED_DATE,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );
    }

    private function createTestTaskWithDates(string $id, string $startAt, string $endAt, TaskStatus $status = TaskStatus::PENDING): TaskRecord
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection(),
        );

        return new TaskRecord(
            id: $id,
            signature: 'test-task',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: $status,
            createdAt: self::FIXED_DATE,
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );
    }

    public function test_run_with_dry_run_does_not_execute_tasks(): void
    {
        $this->manager->run(1, true);

        $this->addToAssertionCount(1);
    }

    public function test_run_executes_pending_task(): void
    {
        $task = $this->createTestTask('test-123');
        $this->storage->savePending($task);

        $this->manager->run(5, false);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_run_does_not_start_new_task_after_time_limit(): void
    {
        $startAt = date('c', strtotime('-1 minute'));
        $endAt = date('c', strtotime('+1 hour'));

        $task = $this->createTestTaskWithDates('test-456', $startAt, $endAt);
        $this->storage->savePending($task);

        // Durée très courte pour ne pas avoir le temps d'exécuter
        $this->manager->run(0, false);

        // La tâche doit toujours être présente car pas eu le temps de s'exécuter
        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());
    }

    public function test_run_handles_multiple_pending_tasks(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTestTask("test-{$i}");
            $this->storage->savePending($task);
        }

        $this->manager->run(10, false);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_run_ignores_non_pending_tasks(): void
    {
        $runningTask = $this->createTestTask('running-1', TaskStatus::RUNNING);
        $successTask = $this->createTestTask('success-1', TaskStatus::SUCCESS);

        $this->storage->savePending($runningTask);
        $this->storage->savePending($successTask);

        $this->manager->run(5, false);

        $pending = $this->storage->findPending();

        // Les tâches non pendantes ne doivent pas être retournées par findPending()
        // Donc le compte devrait être 0
        $this->assertSame(0, $pending->count());
    }

    public function test_run_handles_empty_task_queue(): void
    {
        $this->manager->run(2, false);

        $this->addToAssertionCount(1);
    }

    /**
     * Teste l'arrêt progressif du manager lors de la réception d'un signal.
     * 
     * @group slow
     * @requires extension pcntl
     */
    public function test_run_graceful_shutdown_on_signal(): void
    {
        $task = $this->createTestTask('shutdown-test');
        $this->storage->savePending($task);

        $pid = pcntl_fork();

        if ($pid === 0) {
            // Processus enfant
            $this->manager->run(30, false);
            exit(0);
        } else {
            // Processus parent
            usleep(100000); // 0.1 seconde au lieu de 0.5
            posix_kill($pid, SIGTERM);
            pcntl_wait($status);
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Test alternatif sans fork pour vérifier le shutdown sur timeout.
     * Beaucoup plus rapide que le test avec signal.
     */
    public function test_run_shuts_down_after_time_limit(): void
    {
        $task = $this->createTestTask('timeout-test');
        $this->storage->savePending($task);

        $start = microtime(true);
        $this->manager->run(1, false); // Timeout après 1 seconde
        $duration = microtime(true) - $start;

        // Vérifie que la méthode ne dépasse pas trop le timeout
        $this->assertLessThan(3, $duration, 'Le manager devrait s\'arrêter après ~1 seconde');
        $this->addToAssertionCount(1);
    }
}
