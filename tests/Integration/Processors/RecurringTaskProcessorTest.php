<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Processors;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Loggers\RecurringTaskLogger;
use AndyDefer\Task\Processors\RecurringTaskProcessor;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Runners\RecurringTaskRunner;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

final class RecurringTaskProcessorTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private RecurringTaskProcessor $processor;

    private RecurringTaskRepositoryInterface $repository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runDatabaseMigrations();

        // Repository
        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new RecurringTaskRepository($this->debugRepository);

        // Validator
        $validator = new RecurringTaskValidator;

        // Runner
        $runner = new RecurringTaskRunner(
            validator: $validator,
            logger: App::make(RecurringTaskLogger::class),
            hydration: App::make(HydrationService::class),
            app: App::getFacadeApplication(),
            repository: $this->repository,
        );

        // Processor
        $this->processor = new RecurringTaskProcessor(
            repository: $this->repository,
            runner: $runner,
            validator: $validator,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function createTask(
        string $alias,
        RecurringTaskStatus $status = RecurringTaskStatus::WAITING,
        ?Carbon $startAt = null,
        ?Carbon $endAt = null,
        int $intervalSeconds = 3600,
        ?Carbon $lastRunAt = null
    ): void {
        $startAt = $startAt ?? now();
        $endAt = $endAt ?? now()->addDays(7);

        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO($alias),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO($intervalSeconds),
            start_at: new Iso8601DateTimeVO($startAt->toIso8601String()),
            end_at: new Iso8601DateTimeVO($endAt->toIso8601String()),
            status: $status,
            last_run_at: $lastRunAt ? new Iso8601DateTimeVO($lastRunAt->toIso8601String()) : null,
        );

        $this->repository->create($record);
    }

    private function createFailingTask(
        string $alias,
        RecurringTaskStatus $status = RecurringTaskStatus::WAITING,
        ?Carbon $startAt = null,
        ?Carbon $endAt = null,
        int $intervalSeconds = 3600
    ): void {
        $startAt = $startAt ?? now();
        $endAt = $endAt ?? now()->addDays(7);

        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO($alias),
            fqcn: FailingRecurringTask::class,
            payload: StrictDataObject::from(['should_fail' => true]),
            interval_seconds: new CounterVO($intervalSeconds),
            start_at: new Iso8601DateTimeVO($startAt->toIso8601String()),
            end_at: new Iso8601DateTimeVO($endAt->toIso8601String()),
            status: $status,
        );

        $this->repository->create($record);
    }

    // ==================== TESTS ====================

    public function test_process_starts_waiting_task_when_start_at_reached(): void
    {
        $now = Carbon::now();

        // Tâche en WAITING avec start_at = now - 2h (prête)
        $this->createTask(
            'test-start-1',
            RecurringTaskStatus::WAITING,
            $now->copy()->subHours(2),
            $now->copy()->addDays(7)
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que la tâche est passée en PLAYING
        $task = $this->repository->findByAlias('test-start-1');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
    }

    public function test_process_does_not_start_waiting_task_when_start_at_future(): void
    {
        $now = Carbon::now();

        // Tâche en WAITING avec start_at = now + 2h (pas prête)
        $this->createTask(
            'test-start-future',
            RecurringTaskStatus::WAITING,
            $now->copy()->addHours(2),
            $now->copy()->addDays(7)
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que la tâche est toujours en WAITING
        $task = $this->repository->findByAlias('test-start-future');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::WAITING, $task->getStatus());
    }

    public function test_process_executes_playing_task_when_interval_reached(): void
    {
        $now = Carbon::now();

        // Tâche en PLAYING avec last_run_at = now - 2h et interval = 3600 (1h)
        // → doit être exécutée car 2h >= 1h
        $this->createTask(
            'test-playing-1',
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(1),
            $now->copy()->addDays(7),
            3600,
            $now->copy()->subHours(2)
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que la tâche est toujours en PLAYING
        $task = $this->repository->findByAlias('test-playing-1');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
        $this->assertNotNull($task->getLastRunAt());
    }

    public function test_process_does_not_execute_playing_task_when_interval_not_reached(): void
    {
        $now = Carbon::now();

        // Tâche en PLAYING avec last_run_at = now - 30min et interval = 3600 (1h)
        // → ne doit PAS être exécutée car 30min < 1h
        $this->createTask(
            'test-playing-2',
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(1),
            $now->copy()->addDays(7),
            3600,
            $now->copy()->subMinutes(30)
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que last_run_at n'a pas changé
        $task = $this->repository->findByAlias('test-playing-2');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
        $this->assertNotNull($task->getLastRunAt());
        $lastRun = $task->getLastRunAt()->toDateTime();
        $this->assertEquals($now->copy()->subMinutes(30)->format('Y-m-d H:i'), $lastRun->format('Y-m-d H:i'));
    }

    public function test_process_finishes_task_when_end_at_reached(): void
    {
        $now = Carbon::now();

        // Tâche en PLAYING avec end_at = now - 1h (expirée)
        $this->createTask(
            'test-finish-1',
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(7),
            $now->copy()->subHours(1)
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(1, $result->finished->value);

        // Vérifier que la tâche est passée en FINISHED
        $task = $this->repository->findByAlias('test-finish-1');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::FINISHED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    public function test_process_finishes_waiting_task_when_end_at_reached(): void
    {
        $now = Carbon::now();

        // Tâche en WAITING avec end_at = now - 1h (expirée avant de démarrer)
        $this->createTask(
            'test-finish-waiting',
            RecurringTaskStatus::WAITING,
            $now->copy()->subDays(7),
            $now->copy()->subHours(1)
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(1, $result->finished->value);

        // Vérifier que la tâche est passée en FINISHED
        $task = $this->repository->findByAlias('test-finish-waiting');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::FINISHED, $task->getStatus());
    }

    public function test_process_handles_task_failure(): void
    {
        $now = Carbon::now();

        // Tâche qui échoue
        $this->createFailingTask(
            'test-failing',
            RecurringTaskStatus::WAITING,
            $now->copy()->subHours(2),
            $now->copy()->addDays(7)
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(1, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que la tâche est en PLAYING (même en échec, elle reste active)
        $task = $this->repository->findByAlias('test-failing');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
        $this->assertNotNull($task->getLastRunAt());
    }

    public function test_process_respects_limit(): void
    {
        $now = Carbon::now();

        // Créer 3 tâches prêtes
        for ($i = 1; $i <= 3; $i++) {
            $this->createTask(
                "test-limit-{$i}",
                RecurringTaskStatus::WAITING,
                $now->copy()->subHours(2),
                $now->copy()->addDays(7)
            );
        }

        // Process avec limit = 2
        $result = $this->processor->process(2);

        $this->assertEquals(2, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que seules 2 tâches ont démarré
        $playedTasks = 0;
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->repository->findByAlias("test-limit-{$i}");
            if ($task !== null && $task->getStatus() === RecurringTaskStatus::PLAYING) {
                $playedTasks++;
            }
        }
        $this->assertEquals(2, $playedTasks);
    }

    public function test_process_handles_multiple_scenarios(): void
    {
        $now = Carbon::now();

        // 1. Tâche WAITING prête à démarrer
        $this->createTask(
            'multi-start',
            RecurringTaskStatus::WAITING,
            $now->copy()->subHours(2),
            $now->copy()->addDays(7)
        );

        // 2. Tâche PLAYING prête à être ré-exécutée
        $this->createTask(
            'multi-play',
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(1),
            $now->copy()->addDays(7),
            3600,
            $now->copy()->subHours(2)
        );

        // 3. Tâche PLAYING expirée → FINISHED
        $this->createTask(
            'multi-finish',
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(7),
            $now->copy()->subHours(1)
        );

        $result = $this->processor->process();

        $this->assertEquals(2, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(1, $result->finished->value);

        // Vérifications
        $task1 = $this->repository->findByAlias('multi-start');
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task1->getStatus());

        $task2 = $this->repository->findByAlias('multi-play');
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task2->getStatus());

        $task3 = $this->repository->findByAlias('multi-finish');
        $this->assertEquals(RecurringTaskStatus::FINISHED, $task3->getStatus());
    }

    public function test_process_records_errors_in_result(): void
    {
        $now = Carbon::now();

        // Tâche qui échoue
        $this->createFailingTask(
            'test-error',
            RecurringTaskStatus::WAITING,
            $now->copy()->subHours(2),
            $now->copy()->addDays(7)
        );

        // Tâche expirée
        $this->createTask(
            'test-expired',
            RecurringTaskStatus::PLAYING,
            $now->copy()->subDays(7),
            $now->copy()->subHours(1)
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(1, $result->failed->value);
        $this->assertEquals(1, $result->finished->value);
        $this->assertGreaterThan(0, $result->errors->count());
    }
}
