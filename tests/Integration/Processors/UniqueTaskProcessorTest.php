<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Processors;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Loggers\UniqueTaskLogger;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Processors\UniqueTaskProcessor;
use AndyDefer\Task\Records\UniqueTaskFiltersRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Runners\UniqueTaskRunner;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\UniqueTaskValidator;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Ramsey\Uuid\Uuid;

final class UniqueTaskProcessorTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private UniqueTaskProcessor $processor;

    private UniqueTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runDatabaseMigrations();

        // Repository
        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new UniqueTaskRepository($this->debugRepository);

        // Validator
        $validator = new UniqueTaskValidator;

        // Logger
        $logger = new UniqueTaskLogger(
            logger: App::make(LoggerInterface::class),
            hydration: App::make(HydrationService::class),
        );

        // Runner
        $runner = new UniqueTaskRunner(
            validator: $validator,
            logger: $logger,
            hydration: App::make(HydrationService::class),
            app: App::getFacadeApplication(),
            repository: $this->repository,
        );

        // Processor
        $this->processor = new UniqueTaskProcessor(
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

    private function findTaskByAlias(string $alias): ?UniqueTask
    {
        $filters = new UniqueTaskFiltersRecord(
            alias: new TaskSignatureVO($alias)
        );

        $results = $this->repository->findBy(new FindByRecord(filters: $filters));

        return $results->first() ?? null;
    }

    private function createAndSaveTask(
        string $alias,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?Carbon $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3,
        ?string $fqcn = null
    ): UniqueTaskRecord {
        $scheduledAt = $scheduledAt ?? now();
        $id = $id ?? (string) Uuid::uuid4();
        $fqcn = $fqcn ?? TestUniqueTask::class;

        $task = new UniqueTaskRecord(
            id: new TaskIdVO($id),
            alias: new TaskSignatureVO($alias),
            fqcn: $fqcn,
            payload: StrictDataObject::from(['test' => 'unique']),
            scheduled_at: new Iso8601DateTimeVO($scheduledAt->toIso8601String()),
            grace_period_seconds: $gracePeriodSeconds,
            status: $status,
            attempts: new CounterVO($attempts),
            max_attempts: new CounterVO($maxAttempts),
        );

        $this->repository->create($task);

        return $task;
    }

    private function createFailingTask(
        string $alias,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?Carbon $scheduledAt = null,
        int $gracePeriodSeconds = 86400
    ): UniqueTaskRecord {
        $scheduledAt = $scheduledAt ?? now();
        $id = $id ?? (string) Uuid::uuid4();

        $task = new UniqueTaskRecord(
            id: new TaskIdVO($id),
            alias: new TaskSignatureVO($alias),
            fqcn: FailingTask::class,
            payload: StrictDataObject::from(['test' => 'failing']),
            scheduled_at: new Iso8601DateTimeVO($scheduledAt->toIso8601String()),
            grace_period_seconds: $gracePeriodSeconds,
            status: $status,
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );

        $this->repository->create($task);

        return $task;
    }

    // ==================== TESTS ====================

    public function test_process_executes_ready_tasks(): void
    {
        $now = Carbon::now();

        // Tâche prête : scheduled_at dans le passé
        $this->createAndSaveTask(
            'ready-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2)
        );

        // Tâche prête : scheduled_at = now
        $this->createAndSaveTask(
            'ready-2',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()
        );

        // Tâche pas prête : scheduled_at dans le futur
        $this->createAndSaveTask(
            'not-ready-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->addHours(2)
        );

        $result = $this->processor->process();

        $this->assertEquals(2, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que les tâches prêtes sont en COMPLETED
        $task1 = $this->findTaskByAlias('ready-1');
        $this->assertNotNull($task1);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task1->getStatus());

        $task2 = $this->findTaskByAlias('ready-2');
        $this->assertNotNull($task2);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task2->getStatus());

        // Vérifier que la tâche pas prête est toujours en PENDING
        $task3 = $this->findTaskByAlias('not-ready-1');
        $this->assertNotNull($task3);
        $this->assertEquals(UniqueTaskStatus::PENDING, $task3->getStatus());
    }

    public function test_process_handles_task_failure(): void
    {
        $now = Carbon::now();

        // Tâche qui échoue
        $this->createFailingTask(
            'failing-task',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2)
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(1, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que la tâche est en FAILED
        $task = $this->findTaskByAlias('failing-task');
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());

        // Vérifier que l'erreur est enregistrée
        $this->assertGreaterThan(0, $result->errors->count());
        $error = $result->errors->first();
        $this->assertEquals('Test exception', $error->error);
    }

    public function test_process_respects_limit(): void
    {
        $now = Carbon::now();

        // Créer 5 tâches prêtes
        for ($i = 1; $i <= 5; $i++) {
            $this->createAndSaveTask(
                "ready-{$i}",
                null,
                UniqueTaskStatus::PENDING,
                $now->copy()->subHours(2)
            );
        }

        // Process avec limit = 3
        $result = $this->processor->process(3);

        $this->assertEquals(3, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que seules 3 tâches sont COMPLETED
        $completedCount = 0;
        for ($i = 1; $i <= 5; $i++) {
            $task = $this->findTaskByAlias("ready-{$i}");
            if ($task !== null && $task->getStatus() === UniqueTaskStatus::COMPLETED) {
                $completedCount++;
            }
        }
        $this->assertEquals(3, $completedCount);
    }

    public function test_process_handles_expired_tasks(): void
    {
        $now = Carbon::now();

        // Tâche expirée : scheduled_at + grace_period < now
        $this->createAndSaveTask(
            'expired-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subDays(2),
            86400 // 24h de grace
        );

        // Tâche non expirée : scheduled_at + grace_period > now
        $this->createAndSaveTask(
            'not-expired-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(12),
            86400 // 24h de grace
        );

        $result = $this->processor->process();

        // 1 tâche expirée → failed
        // 1 tâche non expirée → success (car prête)
        $this->assertEquals(1, $result->success->value);
        $this->assertEquals(1, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que la tâche expirée est en FAILED
        $expiredTask = $this->findTaskByAlias('expired-1');
        $this->assertNotNull($expiredTask);
        $this->assertEquals(UniqueTaskStatus::FAILED, $expiredTask->getStatus());

        // Vérifier que la tâche non expirée est en COMPLETED
        $notExpiredTask = $this->findTaskByAlias('not-expired-1');
        $this->assertNotNull($notExpiredTask);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $notExpiredTask->getStatus());
    }

    public function test_process_skips_tasks_with_max_attempts_reached(): void
    {
        $now = Carbon::now();

        // Tâche avec attempts = max_attempts (ne doit pas être exécutée)
        $this->createAndSaveTask(
            'max-attempts-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2),
            86400,
            3,  // attempts = 3
            3   // max_attempts = 3
        );

        // Tâche normale (doit être exécutée)
        $this->createAndSaveTask(
            'normal-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2),
            86400,
            0,  // attempts = 0
            3   // max_attempts = 3
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->value);
        $this->assertEquals(1, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifier que max-attempts-1 est en FAILED
        $maxAttemptsTask = $this->findTaskByAlias('max-attempts-1');
        $this->assertNotNull($maxAttemptsTask);
        $this->assertEquals(UniqueTaskStatus::FAILED, $maxAttemptsTask->getStatus());

        // Vérifier que normal-1 est en COMPLETED
        $normalTask = $this->findTaskByAlias('normal-1');
        $this->assertNotNull($normalTask);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $normalTask->getStatus());
    }

    public function test_process_adds_debug_for_each_execution(): void
    {
        $now = Carbon::now();

        $id = (string) Uuid::uuid4();
        $this->createAndSaveTask(
            'debug-task',
            $id,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2)
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->value);

        // Vérifier que le debug a été ajouté
        $debugs = $this->debugRepository->findByTask('unique', $id);
        $this->assertCount(1, $debugs);

        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Task executed successfully', $debugData->info);
        $this->assertNotNull($debugData->acted_at);
    }

    public function test_process_handles_mixed_scenario(): void
    {
        $now = Carbon::now();

        // 1. Tâche prête → succès
        $this->createAndSaveTask(
            'mixed-success',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2)
        );

        // 2. Tâche qui échoue → failed
        $this->createFailingTask(
            'mixed-failing',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2)
        );

        // 3. Tâche expirée → failed
        $this->createAndSaveTask(
            'mixed-expired',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subDays(2),
            86400
        );

        // 4. Tâche avec max_attempts atteint → failed
        $this->createAndSaveTask(
            'mixed-max-attempts',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2),
            86400,
            3,  // attempts = 3
            3   // max_attempts = 3
        );

        // 5. Tâche pas prête (future) → ignorée (reste PENDING)
        $this->createAndSaveTask(
            'mixed-future',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->addHours(2)
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->value);
        $this->assertEquals(3, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);

        // Vérifications
        $task1 = $this->findTaskByAlias('mixed-success');
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task1->getStatus());

        $task2 = $this->findTaskByAlias('mixed-failing');
        $this->assertEquals(UniqueTaskStatus::FAILED, $task2->getStatus());

        $task3 = $this->findTaskByAlias('mixed-expired');
        $this->assertEquals(UniqueTaskStatus::FAILED, $task3->getStatus());

        $task4 = $this->findTaskByAlias('mixed-max-attempts');
        $this->assertEquals(UniqueTaskStatus::FAILED, $task4->getStatus());

        $task5 = $this->findTaskByAlias('mixed-future');
        $this->assertEquals(UniqueTaskStatus::PENDING, $task5->getStatus());
    }

    public function test_process_records_errors_in_result(): void
    {
        $now = Carbon::now();

        // Tâche qui échoue
        $id = (string) Uuid::uuid4();
        $this->createFailingTask(
            'error-task',
            $id,  // ✅ Passer l'ID explicitement
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2)
        );

        $result = $this->processor->process();

        $this->assertGreaterThan(0, $result->errors->count());

        $error = $result->errors->first();
        $this->assertEquals('Test exception', $error->error);
        // ✅ Vérifier l'ID, pas l'alias
        $this->assertEquals($id, $error->identifier);
    }

    public function test_process_does_not_execute_tasks_not_in_pending_status(): void
    {
        $now = Carbon::now();

        // Tâche en COMPLETED (ne doit pas être exécutée)
        $this->createAndSaveTask(
            'completed-1',
            null,
            UniqueTaskStatus::COMPLETED,
            $now->copy()->subHours(2)
        );

        // Tâche en FAILED (ne doit pas être exécutée)
        $this->createAndSaveTask(
            'failed-1',
            null,
            UniqueTaskStatus::FAILED,
            $now->copy()->subHours(2)
        );

        // Tâche en PENDING (doit être exécutée)
        $this->createAndSaveTask(
            'pending-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2)
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->value);
        $this->assertEquals(0, $result->failed->value);

        // Vérifier que les tâches COMPLETED et FAILED sont inchangées
        $completed = $this->findTaskByAlias('completed-1');
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $completed->getStatus());

        $failed = $this->findTaskByAlias('failed-1');
        $this->assertEquals(UniqueTaskStatus::FAILED, $failed->getStatus());

        // Vérifier que la tâche PENDING est passée en COMPLETED
        $pending = $this->findTaskByAlias('pending-1');
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $pending->getStatus());
    }

    public function test_process_handles_empty_tasks(): void
    {
        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);
        $this->assertCount(0, $result->errors);
    }

    public function test_process_with_limit_0_does_nothing(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask(
            'limit-zero',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2)
        );

        $result = $this->processor->process(0);

        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(0, $result->failed->value);

        // Vérifier que la tâche est toujours en PENDING
        $task = $this->findTaskByAlias('limit-zero');
        $this->assertEquals(UniqueTaskStatus::PENDING, $task->getStatus());
    }

    public function test_process_moves_expired_tasks_to_failed_even_if_not_ready(): void
    {
        $now = Carbon::now();

        // Tâche expirée avec scheduled_at dans le passé
        $this->createAndSaveTask(
            'expired-future',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subDays(1),
            3600 // 1h de grace
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(1, $result->failed->value);

        $task = $this->findTaskByAlias('expired-future');
        $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
    }

    public function test_process_uses_validator_to_check_tasks_before_execution(): void
    {
        $now = Carbon::now();

        // Tâche avec scheduled_at dans le passé mais max_attempts atteint
        $this->createAndSaveTask(
            'validator-check',
            null,
            UniqueTaskStatus::PENDING,
            $now->copy()->subHours(2),
            86400,
            3,  // attempts = 3 (max atteint)
            3   // max_attempts = 3
        );

        $result = $this->processor->process();

        // La tâche ne doit pas être exécutée, mais marquée comme FAILED
        $this->assertEquals(0, $result->success->value);
        $this->assertEquals(1, $result->failed->value);

        $task = $this->findTaskByAlias('validator-check');
        $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());

        // Vérifier que l'erreur de validation est présente
        $this->assertGreaterThan(0, $result->errors->count());
        $error = $result->errors->first();
        $this->assertStringContainsString('Validation failed', $error->error);
    }
}
