<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Runners;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Loggers\RecurringTaskLogger;
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
use Illuminate\Support\Facades\App;

final class RecurringTaskRunnerTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private RecurringTaskRunner $runner;

    private RecurringTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    private RecurringTaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        // Repository
        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new RecurringTaskRepository($this->debugRepository);

        // Validator
        $this->validator = new RecurringTaskValidator;

        // Logger
        $logger = new RecurringTaskLogger(
            logger: App::make(LoggerInterface::class),
            hydration: App::make(HydrationService::class),
        );

        // Runner
        $this->runner = new RecurringTaskRunner(
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
        RecurringTaskStatus $status = RecurringTaskStatus::PLAYING,
        ?string $fqcn = null,
        ?\DateTimeInterface $lastRunAt = null
    ): RecurringTaskRecord {
        $fqcn = $fqcn ?? TestRecurringTask::class;
        $now = now();

        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO($alias),
            fqcn: $fqcn,
            payload: StrictDataObject::from(['test' => 'runner']),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO($now->subDay()->toIso8601String()),
            end_at: new Iso8601DateTimeVO($now->addDays(7)->toIso8601String()),
            status: $status,
            last_run_at: $lastRunAt ? new Iso8601DateTimeVO($lastRunAt->format('Y-m-d\TH:i:sP')) : null,
        );

        $this->repository->create($record);

        return $record;
    }

    // ==================== TESTS ====================

    public function test_run_successfully_executes_task(): void
    {
        $record = $this->createTaskRecord('test-run-success');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertGreaterThanOrEqual(0, $result->execution_time);

        // Vérifier que last_run_at a été mis à jour
        $task = $this->repository->findByAlias('test-run-success');
        $this->assertNotNull($task);
        $this->assertNotNull($task->getLastRunAt());

        // Vérifier que le debug a été ajouté
        $debugs = $this->debugRepository->findByTask('recurring', 'test-run-success');
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Recurring task executed successfully', $debugData->info);
    }

    public function test_run_returns_failure_when_task_not_in_playing_status(): void
    {
        // Tâche en WAITING (non exécutable)
        $record = $this->createTaskRecord('test-run-waiting', RecurringTaskStatus::WAITING);

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals('test-run-waiting', $result->error->alias);
        $this->assertStringContainsString('Validation failed', $result->error->error);

        // Vérifier que last_run_at n'a pas été mis à jour
        $task = $this->repository->findByAlias('test-run-waiting');
        $this->assertNotNull($task);
        $this->assertNull($task->getLastRunAt());
    }

    public function test_run_returns_failure_when_task_expired(): void
    {
        $now = now();

        // Créer une tâche avec end_at dans le passé (expirée)
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test-run-expired'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from(['test' => 'runner']),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO($now->subDays(7)->toIso8601String()),
            end_at: new Iso8601DateTimeVO($now->subDay()->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->repository->create($record);

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Validation failed', $result->error->error);
    }

    public function test_run_skips_execution_when_interval_not_reached(): void
    {
        $now = now();

        // Tâche en PLAYING avec last_run_at = now - 30min, interval = 3600 (1h)
        $record = $this->createTaskRecord(
            'test-run-skip',
            RecurringTaskStatus::PLAYING,
            TestRecurringTask::class,
            $now->copy()->subMinutes(30)
        );

        $result = $this->runner->run($record);

        // shouldRunAgain() retourne false car 30min < 1h
        // Donc la tâche n'est pas exécutée, mais ce n'est pas une erreur
        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertEquals(0.0, $result->execution_time);

        // Vérifier que last_run_at n'a PAS été mis à jour
        $task = $this->repository->findByAlias('test-run-skip');
        $this->assertNotNull($task);
        $this->assertEquals(
            $now->copy()->subMinutes(30)->format('Y-m-d H:i'),
            $task->getLastRunAt()->toDateTime()->format('Y-m-d H:i')
        );

        // Vérifier qu'aucun debug n'a été ajouté
        $debugs = $this->debugRepository->findByTask('recurring', 'test-run-skip');
        $this->assertCount(0, $debugs);
    }

    public function test_run_handles_task_exception(): void
    {
        $now = now();

        // Créer une tâche qui échoue
        $failingRecord = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test-run-failing'),
            fqcn: FailingRecurringTask::class,
            payload: StrictDataObject::from(['should_fail' => true, 'fail_message' => 'Test failure']),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO($now->subDay()->toIso8601String()),
            end_at: new Iso8601DateTimeVO($now->addDays(7)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->repository->create($failingRecord);

        $result = $this->runner->run($failingRecord);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals('Test failure', $result->error->error);

        // Vérifier que last_run_at a été mis à jour (même en échec)
        $task = $this->repository->findByAlias('test-run-failing');
        $this->assertNotNull($task);
        $this->assertNotNull($task->getLastRunAt());

        // Vérifier que le debug avec l'erreur a été ajouté
        $debugs = $this->debugRepository->findByTask('recurring', 'test-run-failing');
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('failed', $debugData->status);
        $this->assertEquals('Test failure', $debugData->info);
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
        $debugs = $this->debugRepository->findByTask('recurring', 'test-run-logs');
        $this->assertCount(1, $debugs);
        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Recurring task executed successfully', $debugData->info);
        $this->assertNotNull($debugData->acted_at);
    }

    public function test_run_preserves_task_in_playing_status(): void
    {
        $record = $this->createTaskRecord('test-run-preserve');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        // Vérifier que la tâche est toujours en PLAYING
        $task = $this->repository->findByAlias('test-run-preserve');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
    }

    public function test_run_does_not_change_other_task_data(): void
    {
        $alias = 'test-run-data';
        $now = now();

        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO($alias),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from(['test' => 'runner', 'data' => 'should_persist']),
            interval_seconds: new CounterVO(7200),
            start_at: new Iso8601DateTimeVO($now->subDays(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO($now->addDays(14)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->repository->create($record);

        $result = $this->runner->run($record);
        $this->assertTrue($result->success);

        $task = $this->repository->findByAlias($alias);
        $this->assertNotNull($task);

        // Vérifier que les données n'ont pas changé
        $this->assertEquals(TestRecurringTask::class, $task->getFqcn());
        $this->assertEquals(7200, $task->getIntervalSeconds()->value);
        $this->assertEquals('should_persist', $task->getPayload()->toArray()['data']);
    }

    public function test_run_handles_null_last_run_at(): void
    {
        // Tâche en PLAYING sans last_run_at (première exécution)
        $record = $this->createTaskRecord(
            'test-run-first',
            RecurringTaskStatus::PLAYING,
            null,
            null  // last_run_at = null
        );

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        // Vérifier que last_run_at a été défini
        $task = $this->repository->findByAlias('test-run-first');
        $this->assertNotNull($task);
        $this->assertNotNull($task->getLastRunAt());
    }
}
