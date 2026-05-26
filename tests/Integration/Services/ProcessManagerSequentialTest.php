<?php

// tests/Integration/Services/ProcessManagerSequentialTest.php

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

final class ProcessManagerSequentialTest extends IntegrationTestCase
{
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
        $this->manager = new ProcessManager(
            runner: $this->runner,
            storage: $this->storage,
            logger: $this->logger,
            validator: $this->validator,
            useSequentialMode: true,
        );
    }

    private function createTestTask(string $id, TaskStatus $status = TaskStatus::PENDING): TaskRecord
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        return new TaskRecord(
            id: $id,
            signature: 'test-task',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: $status,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );
    }

    public function test_sequential_mode_executes_tasks_one_by_one(): void
    {
        $task1 = $this->createTestTask('seq-1');
        $task2 = $this->createTestTask('seq-2');

        $this->storage->savePending($task1);
        $this->storage->savePending($task2);

        $startTime = microtime(true);
        $this->manager->run(5, false);
        $duration = microtime(true) - $startTime;

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());

        // En mode séquentiel, l'exécution ne devrait pas prendre plus de temps
        $this->assertLessThan(10, $duration);
    }

    public function test_sequential_mode_handles_empty_queue(): void
    {
        $this->manager->run(2, false);
        $this->addToAssertionCount(1);
    }

    public function test_sequential_mode_respects_time_limit(): void
    {
        $task = $this->createTestTask('timeout-seq');
        $this->storage->savePending($task);

        $startTime = microtime(true);
        $this->manager->run(1, false);
        $duration = microtime(true) - $startTime;

        $this->assertLessThan(3, $duration);
    }

    public function test_sequential_mode_handles_multiple_tasks_within_timeout(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $task = $this->createTestTask("batch-{$i}");
            $this->storage->savePending($task);
        }

        $this->manager->run(10, false);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }
}
