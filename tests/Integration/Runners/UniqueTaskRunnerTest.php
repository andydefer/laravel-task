<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Runners;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Loggers\UniqueTaskLogger;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Runners\UniqueTaskRunner;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTaskWithCustomConfig;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\UniqueTaskValidator;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\App;
use Ramsey\Uuid\Uuid;

final class UniqueTaskRunnerTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private UniqueTaskRunner $runner;

    private UniqueTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    private UniqueTaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runDatabaseMigrations();

        // Repository
        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new UniqueTaskRepository($this->debugRepository);

        // Validator
        $this->validator = new UniqueTaskValidator;

        // Logger
        $logger = new UniqueTaskLogger(
            logger: App::make(LoggerInterface::class),
            hydration: App::make(HydrationService::class),
        );

        // Runner
        $this->runner = new UniqueTaskRunner(
            validator: $this->validator,
            logger: $logger,
            hydration: App::make(HydrationService::class),
            app: App::getFacadeApplication(),
            repository: $this->repository,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function createTaskRecord(
        string $alias,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3,
        ?string $fqcn = null
    ): UniqueTaskRecord {
        $scheduledAt = $scheduledAt ?? now();
        $id = $id ?? (string) Uuid::uuid4();
        $fqcn = $fqcn ?? TestUniqueTask::class;

        $record = new UniqueTaskRecord(
            id: new TaskIdVO($id),
            alias: new TaskSignatureVO($alias),
            fqcn: $fqcn,
            payload: StrictDataObject::from(['test' => 'runner']),
            scheduled_at: new Iso8601DateTimeVO($scheduledAt->format('Y-m-d\TH:i:sP')),
            grace_period_seconds: $gracePeriodSeconds,
            status: $status,
            attempts: new CounterVO($attempts),
            max_attempts: new CounterVO($maxAttempts),
        );

        $this->repository->create($record);

        return $record;
    }

    private function findTaskById(string $id): ?UniqueTask
    {
        return $this->repository->findById($id);
    }

    // ==================== TESTS ====================

    public function test_run_successfully_executes_task(): void
    {
        $record = $this->createTaskRecord('test-run-success');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertGreaterThanOrEqual(0, $result->execution_time);

        // Vérifier que la tâche est en COMPLETED
        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());

        // Vérifier que le debug a été ajouté
        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Task executed successfully', $debugData->info);
    }

    public function test_run_returns_failure_when_task_not_in_pending_status(): void
    {
        // Tâche en COMPLETED
        $record = $this->createTaskRecord(
            'test-run-completed',
            null,
            UniqueTaskStatus::COMPLETED
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->id->value, $result->error->identifier);
        $this->assertStringContainsString('Validation failed', $result->error->error);
        $this->assertStringContainsString('Task is not in PENDING state', $result->error->error);
    }

    public function test_run_returns_failure_when_scheduled_at_in_future(): void
    {
        // Tâche avec scheduled_at dans le futur
        $record = $this->createTaskRecord(
            'test-run-future',
            null,
            UniqueTaskStatus::PENDING,
            now()->addHours(2)
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->id->value, $result->error->identifier);
        $this->assertStringContainsString('Validation failed', $result->error->error);
        $this->assertStringContainsString('Task is not ready to run', $result->error->error);
    }

    public function test_run_returns_failure_when_max_attempts_reached(): void
    {
        // Tâche avec attempts = max_attempts
        $record = $this->createTaskRecord(
            'test-run-max-attempts',
            null,
            UniqueTaskStatus::PENDING,
            now()->subHours(2),
            86400,
            3,  // attempts
            3   // max_attempts
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->id->value, $result->error->identifier);
        $this->assertStringContainsString('Validation failed', $result->error->error);
        $this->assertStringContainsString('Maximum attempts reached', $result->error->error);
    }

    public function test_run_returns_failure_when_task_expired(): void
    {
        // Tâche expirée : scheduled_at + grace_period < now
        $record = $this->createTaskRecord(
            'test-run-expired',
            null,
            UniqueTaskStatus::PENDING,
            now()->subDays(2),
            3600  // 1h de grace (expiré depuis longtemps)
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals($record->id->value, $result->error->identifier);
        $this->assertStringContainsString('Validation failed', $result->error->error);
        $this->assertStringContainsString('Task has expired', $result->error->error);
    }

    public function test_run_handles_task_exception(): void
    {
        $record = $this->createTaskRecord(
            'test-run-failing',
            null,
            UniqueTaskStatus::PENDING,
            now()->subHours(2),
            86400,
            0,
            3,
            FailingTask::class
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals('Test exception', $result->error->error);

        // Vérifier que la tâche est en FAILED
        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());

        // Vérifier que le debug avec l'erreur a été ajouté
        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('failed', $debugData->status);
        $this->assertEquals('Test exception', $debugData->info);
    }

    public function test_run_returns_execution_time(): void
    {
        $record = $this->createTaskRecord('test-run-time');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertIsFloat($result->execution_time);
        $this->assertGreaterThanOrEqual(0, $result->execution_time);
    }

    public function test_run_logs_start_and_success(): void
    {
        $record = $this->createTaskRecord('test-run-logs');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        // Vérifier que le debug a été ajouté
        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Task executed successfully', $debugData->info);
        $this->assertNotNull($debugData->acted_at);
    }

    public function test_run_sets_completed_status_on_success(): void
    {
        $record = $this->createTaskRecord('test-run-completed-status');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    public function test_run_sets_failed_status_on_failure(): void
    {
        $record = $this->createTaskRecord(
            'test-run-failed-status',
            null,
            UniqueTaskStatus::PENDING,
            now()->subHours(2),
            86400,
            0,
            3,
            FailingTask::class
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);

        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    public function test_run_does_not_change_other_task_data(): void
    {
        $alias = 'test-run-data';
        $id = (string) Uuid::uuid4();

        $record = new UniqueTaskRecord(
            id: new TaskIdVO($id),
            alias: new TaskSignatureVO($alias),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from(['test' => 'runner', 'data' => 'should_persist']),
            scheduled_at: new Iso8601DateTimeVO(now()->subHours(2)->format('Y-m-d\TH:i:sP')),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );

        $this->repository->create($record);

        $result = $this->runner->run($record);
        $this->assertTrue($result->success);

        $task = $this->findTaskById($id);
        $this->assertNotNull($task);

        // Vérifier que les données n'ont pas changé
        $this->assertEquals(TestUniqueTask::class, $task->getFqcn());
        $this->assertEquals('should_persist', $task->getPayload()->toArray()['data']);
        $this->assertEquals(86400, $task->getGracePeriodSeconds());
    }

    public function test_run_with_custom_task_class(): void
    {
        $record = $this->createTaskRecord(
            'test-run-custom',
            null,
            UniqueTaskStatus::PENDING,
            now()->subHours(2),
            86400,
            0,
            3,
            TestUniqueTaskWithCustomConfig::class
        );

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);

        // Vérifier que la tâche est en COMPLETED
        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
    }

    public function test_run_handles_null_payload(): void
    {
        $id = (string) Uuid::uuid4();

        $record = new UniqueTaskRecord(
            id: new TaskIdVO($id),
            alias: new TaskSignatureVO('test-null-payload'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),  // Payload vide
            scheduled_at: new Iso8601DateTimeVO(now()->subHours(2)->format('Y-m-d\TH:i:sP')),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );

        $this->repository->create($record);

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $task = $this->findTaskById($id);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
    }

    public function test_run_adds_debug_on_success(): void
    {
        $record = $this->createTaskRecord('test-run-debug-success');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(1, $debugs);

        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Task executed successfully', $debugData->info);
        $this->assertNotNull($debugData->acted_at);
    }

    public function test_run_adds_debug_on_failure(): void
    {
        $record = $this->createTaskRecord(
            'test-run-debug-failure',
            null,
            UniqueTaskStatus::PENDING,
            now()->subHours(2),
            86400,
            0,
            3,
            FailingTask::class
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);

        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(1, $debugs);

        $debugData = $debugs->first()->getData();
        $this->assertEquals('failed', $debugData->status);
        $this->assertEquals('Test exception', $debugData->info);
        $this->assertNotNull($debugData->acted_at);
    }

    public function test_run_does_not_update_task_when_validation_fails(): void
    {
        // Tâche avec scheduled_at dans le futur (validation échoue)
        $record = $this->createTaskRecord(
            'test-run-no-update',
            null,
            UniqueTaskStatus::PENDING,
            now()->addHours(2)  // Future
        );

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);

        // Vérifier que la tâche est toujours en PENDING
        $task = $this->findTaskById($record->id->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::PENDING, $task->getStatus());
        $this->assertNull($task->getFinishedAt());

        // Vérifier qu'aucun debug n'a été ajouté
        $debugs = $this->debugRepository->findByTask('unique', $record->id->value);
        $this->assertCount(0, $debugs);
    }
}
