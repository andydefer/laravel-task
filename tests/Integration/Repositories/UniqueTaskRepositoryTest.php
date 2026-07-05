<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Repositories;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\Logger\Configs\LoggerConfig;
use AndyDefer\Logger\LoggerService;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\UniqueTaskFiltersRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskTypeVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

final class UniqueTaskRepositoryTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private UniqueTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    private LoggerService $logger;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new LoggerConfig($this->app->make(ConfigRepository::class));
        $this->logPath = $config->basePath();
        $fs = new FileSystemService;

        if (! $fs->isDirectory($this->logPath)) {
            $fs->makeDirectory($this->logPath, PermissionMode::DIRECTORY, true);
        }

        $pathStrategy = new TemporalPathStrategy($this->logPath);
        $jsonlContext = new JsonlContext;

        $jsonlService = new JsonlService(
            pathStrategy: $pathStrategy,
            fileSystem: $fs,
            context: $jsonlContext,
            defaultBufferSize: $config->bufferSize(),
        );

        $hydration = new HydrationService;

        $this->logger = new LoggerService(
            jsonlService: $jsonlService,
            hydrationService: $hydration,
        );

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new UniqueTaskRepository($this->debugRepository, $this->logger);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $fs = new FileSystemService;
        if ($fs->isDirectory($this->logPath)) {
            $fs->deleteDirectory($this->logPath);
        }
    }

    // ==================== HELPERS ====================

    private function generateUuid(): string
    {
        return Uuid::uuid4()->toString();
    }

    private function generateUuidVO(): UuidVO
    {
        return new UuidVO($this->generateUuid());
    }

    private function createAliasVO(?string $uuid = null): TaskAliasVO
    {
        $uuid = $uuid ?? $this->generateUuid();

        return new TaskAliasVO(
            type: new TaskTypeVO('unique'),
            uuid: $uuid
        );
    }

    private function createFqcnVO(): UniqueTaskFqcnVO
    {
        return new UniqueTaskFqcnVO(TestUniqueTask::class);
    }

    private function createIdVO(?string $id = null): UuidVO
    {
        $id = $id ?? $this->generateUuid();

        return new UuidVO($id);
    }

    private function createTaskRecord(
        ?string $alias = null,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3
    ): UniqueTaskRecord {
        $scheduledAt = $scheduledAt ?? now();
        $id = $id ?? $this->generateUuid();
        $alias = $alias ?? $this->generateUuid();

        return UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $this->createAliasVO($alias),
            'fqcn' => $this->createFqcnVO(),
            'payload' => ['test' => 'unique'],
            'scheduled_at' => new Iso8601DateTimeVO($scheduledAt->format('Y-m-d\TH:i:sP')),
            'grace_period_seconds' => new DurationVO($gracePeriodSeconds),
            'status' => $status,
            'attempts' => new CounterVO($attempts),
            'max_attempts' => new MaxFailedAttemptsVO($maxAttempts),
        ]);
    }

    private function createAndSaveTask(
        ?string $alias = null,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3
    ): UniqueTask {
        $id = $id ?? $this->generateUuid();
        $alias = $alias ?? $this->generateUuid();

        $record = $this->createTaskRecord($alias, $id, $status, $scheduledAt, $gracePeriodSeconds, $attempts, $maxAttempts);

        return $this->repository->create($record);
    }

    private function createCanceledTask(): UniqueTask
    {
        return $this->createAndSaveTask(null, null, UniqueTaskStatus::CANCELED);
    }

    // ==================== TESTS FINDERS ====================

    public function test_find_pending_returns_collection(): void
    {
        $this->createAndSaveTask();
        $this->createAndSaveTask();
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);

        $pending = $this->repository->findPending(new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $pending);
        $this->assertCount(2, $pending);

        foreach ($pending as $task) {
            $this->assertInstanceOf(UniqueTask::class, $task);
            $this->assertEquals(UniqueTaskStatus::PENDING, $task->getStatus());
        }
    }

    public function test_find_pending_with_limit(): void
    {
        $this->createAndSaveTask();
        $this->createAndSaveTask();
        $this->createAndSaveTask();

        $pending = $this->repository->findPending(new LimitVO(2));

        $this->assertInstanceOf(Collection::class, $pending);
        $this->assertCount(2, $pending);
    }

    public function test_find_pending_returns_empty_collection_when_none(): void
    {
        $pending = $this->repository->findPending(new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $pending);
        $this->assertCount(0, $pending);
    }

    // ==================== TESTS FIND COMPLETED ====================

    public function test_find_completed_returns_collection(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);

        $completed = $this->repository->findCompleted(new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $completed);
        $this->assertCount(2, $completed);

        foreach ($completed as $task) {
            $this->assertInstanceOf(UniqueTask::class, $task);
            $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
        }
    }

    public function test_find_completed_with_limit(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);

        $completed = $this->repository->findCompleted(new LimitVO(2));

        $this->assertInstanceOf(Collection::class, $completed);
        $this->assertCount(2, $completed);
    }

    public function test_find_completed_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);

        $completed = $this->repository->findCompleted(new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $completed);
        $this->assertCount(0, $completed);
    }

    // ==================== TESTS FIND FAILED ====================

    public function test_find_failed_returns_collection(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);

        $failed = $this->repository->findFailed(new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $failed);
        $this->assertCount(2, $failed);

        foreach ($failed as $task) {
            $this->assertInstanceOf(UniqueTask::class, $task);
            $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
        }
    }

    public function test_find_failed_with_limit(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::FAILED);

        $failed = $this->repository->findFailed(new LimitVO(2));

        $this->assertInstanceOf(Collection::class, $failed);
        $this->assertCount(2, $failed);
    }

    public function test_find_failed_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);

        $failed = $this->repository->findFailed(new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $failed);
        $this->assertCount(0, $failed);
    }

    // ==================== TESTS FIND CANCELED ====================

    public function test_find_canceled_returns_collection(): void
    {
        $this->createCanceledTask();
        $this->createCanceledTask();
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);

        $canceled = $this->repository->findCanceled(new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(2, $canceled);

        foreach ($canceled as $task) {
            $this->assertInstanceOf(UniqueTask::class, $task);
            $this->assertEquals(UniqueTaskStatus::CANCELED, $task->getStatus());
        }
    }

    public function test_find_canceled_with_limit(): void
    {
        $this->createCanceledTask();
        $this->createCanceledTask();
        $this->createCanceledTask();

        $canceled = $this->repository->findCanceled(new LimitVO(2));

        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(2, $canceled);
    }

    public function test_find_canceled_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);

        $canceled = $this->repository->findCanceled(new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(0, $canceled);
    }

    // ==================== TESTS READY TO RUN ====================

    public function test_find_ready_to_run_returns_collection(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subHours(2));
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy());
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->addHours(2));
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED, $now->copy()->subHours(2));

        $nowVO = new Iso8601DateTimeVO($now->format('Y-m-d\TH:i:sP'));
        $ready = $this->repository->findReadyToRun($nowVO, new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $ready);
        $this->assertCount(2, $ready);
    }

    public function test_find_ready_to_run_with_limit(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subHours(2));
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subHours(1));
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy());

        $nowVO = new Iso8601DateTimeVO($now->format('Y-m-d\TH:i:sP'));
        $ready = $this->repository->findReadyToRun($nowVO, new LimitVO(2));

        $this->assertInstanceOf(Collection::class, $ready);
        $this->assertCount(2, $ready);
    }

    public function test_find_ready_to_run_returns_empty_collection_when_none(): void
    {
        $now = Carbon::now();
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->addHours(2));

        $nowVO = new Iso8601DateTimeVO($now->format('Y-m-d\TH:i:sP'));
        $ready = $this->repository->findReadyToRun($nowVO, new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $ready);
        $this->assertCount(0, $ready);
    }

    // ==================== TESTS FIND EXPIRED ====================

    public function test_find_expired_returns_collection(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subDays(2), 86400);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subHours(12), 86400);

        $nowVO = new Iso8601DateTimeVO($now->format('Y-m-d\TH:i:sP'));
        $expired = $this->repository->findExpired($nowVO, new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $expired);
        $this->assertCount(1, $expired);
    }

    public function test_find_expired_with_limit(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subDays(2), 86400);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subDays(1), 86400);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subHours(12), 86400);

        $nowVO = new Iso8601DateTimeVO($now->format('Y-m-d\TH:i:sP'));
        $expired = $this->repository->findExpired($nowVO, new LimitVO(1));

        $this->assertInstanceOf(Collection::class, $expired);
        $this->assertCount(1, $expired);
    }

    public function test_find_expired_returns_empty_collection_when_none(): void
    {
        $now = Carbon::now();
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subHours(12), 86400);

        $nowVO = new Iso8601DateTimeVO($now->format('Y-m-d\TH:i:sP'));
        $expired = $this->repository->findExpired($nowVO, new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $expired);
        $this->assertCount(0, $expired);
    }

    // ==================== TESTS findById ====================

    public function test_find_by_id_returns_model(): void
    {
        $id = $this->generateUuid();
        $this->createAndSaveTask(null, $id, UniqueTaskStatus::PENDING);

        $found = $this->repository->findById(new UuidVO($id));

        $this->assertNotNull($found);
        $this->assertInstanceOf(UniqueTask::class, $found);
        $this->assertEquals($id, $found->getId()->getValue());
        $this->assertEquals(UniqueTaskStatus::PENDING, $found->getStatus());
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $found = $this->repository->findById(new UuidVO('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertNull($found);
    }

    // ==================== TESTS findByAlias ====================

    public function test_find_by_alias_returns_model(): void
    {
        $alias = $this->generateUuid();
        $this->createAndSaveTask($alias, null, UniqueTaskStatus::PENDING);

        $found = $this->repository->findByAlias($this->createAliasVO($alias));

        $this->assertNotNull($found);
        $this->assertInstanceOf(UniqueTask::class, $found);
        $this->assertEquals('unique@'.$alias, $found->getAlias()->getValue());
        $this->assertEquals(UniqueTaskStatus::PENDING, $found->getStatus());
    }

    public function test_find_by_alias_returns_null_when_not_found(): void
    {
        $alias = $this->generateUuid();
        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNull($found);
    }

    // ==================== TESTS MOVES ====================

    public function test_move_to_completed_updates_status(): void
    {
        $id = $this->generateUuid();
        $taskModel = $this->createAndSaveTask(null, $id, UniqueTaskStatus::PENDING);

        $taskRecord = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $taskModel->getAlias(),
        ]);

        $result = $this->repository->moveToCompleted($taskRecord);
        $this->assertTrue($result);

        $found = $this->repository->findById(new UuidVO($id));
        $this->assertNotNull($found);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
    }

    public function test_move_to_completed_returns_false_when_task_not_found(): void
    {
        $taskRecord = UniqueTaskRecord::from([
            'id' => new UuidVO('550e8400-e29b-41d4-a716-446655440000'),
            'alias' => $this->createAliasVO(),
            'fqcn' => $this->createFqcnVO(),
            'payload' => [],
            'scheduled_at' => new Iso8601DateTimeVO(now()->format('Y-m-d\TH:i:sP')),
        ]);

        $result = $this->repository->moveToCompleted($taskRecord);
        $this->assertFalse($result);
    }

    public function test_move_to_failed_updates_status(): void
    {
        $id = $this->generateUuid();
        $taskModel = $this->createAndSaveTask(null, $id, UniqueTaskStatus::PENDING);

        $taskRecord = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $taskModel->getAlias(),
        ]);

        $result = $this->repository->moveToFailed($taskRecord);
        $this->assertTrue($result);

        $found = $this->repository->findById(new UuidVO($id));
        $this->assertNotNull($found);
        $this->assertEquals(UniqueTaskStatus::FAILED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
    }

    public function test_move_to_failed_returns_false_when_task_not_found(): void
    {
        $taskRecord = UniqueTaskRecord::from([
            'id' => new UuidVO('550e8400-e29b-41d4-a716-446655440000'),
            'alias' => $this->createAliasVO(),
            'fqcn' => $this->createFqcnVO(),
            'payload' => [],
            'scheduled_at' => new Iso8601DateTimeVO(now()->format('Y-m-d\TH:i:sP')),
        ]);

        $result = $this->repository->moveToFailed($taskRecord);
        $this->assertFalse($result);
    }

    public function test_move_to_canceled_updates_status(): void
    {
        $id = $this->generateUuid();
        $taskModel = $this->createAndSaveTask(null, $id, UniqueTaskStatus::PENDING);

        $taskRecord = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $taskModel->getAlias(),
        ]);

        $result = $this->repository->moveToCanceled($taskRecord);
        $this->assertTrue($result);

        $found = $this->repository->findById(new UuidVO($id));
        $this->assertNotNull($found);
        $this->assertEquals(UniqueTaskStatus::CANCELED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
    }

    public function test_move_to_canceled_returns_false_when_task_not_found(): void
    {
        $taskRecord = UniqueTaskRecord::from([
            'id' => new UuidVO('550e8400-e29b-41d4-a716-446655440000'),
            'alias' => $this->createAliasVO(),
            'fqcn' => $this->createFqcnVO(),
            'payload' => [],
            'scheduled_at' => new Iso8601DateTimeVO(now()->format('Y-m-d\TH:i:sP')),
        ]);

        $result = $this->repository->moveToCanceled($taskRecord);
        $this->assertFalse($result);
    }

    // ==================== TESTS UPDATE ATTEMPTS ====================

    public function test_update_attempts_updates_attempts(): void
    {
        $id = $this->generateUuid();
        $taskModel = $this->createAndSaveTask(null, $id, UniqueTaskStatus::PENDING);

        $taskRecord = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $taskModel->getAlias(),
        ]);

        $result = $this->repository->updateAttempts($taskRecord, new CounterVO(2));
        $this->assertTrue($result);

        $found = $this->repository->findById(new UuidVO($id));
        $this->assertNotNull($found);
        $this->assertEquals(2, $found->getAttempts()->getValue());
    }

    public function test_update_attempts_returns_false_when_task_not_found(): void
    {
        $taskRecord = UniqueTaskRecord::from([
            'id' => new UuidVO('550e8400-e29b-41d4-a716-446655440000'),
            'alias' => $this->createAliasVO(),
            'fqcn' => $this->createFqcnVO(),
            'payload' => [],
            'scheduled_at' => new Iso8601DateTimeVO(now()->format('Y-m-d\TH:i:sP')),
        ]);

        $result = $this->repository->updateAttempts($taskRecord, new CounterVO(2));
        $this->assertFalse($result);
    }

    // ==================== TESTS COUNTS ====================

    public function test_count_pending(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);

        $this->assertEquals(2, $this->repository->countPending()->getValue());
    }

    public function test_count_pending_returns_zero_when_none(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);
        $this->assertEquals(0, $this->repository->countPending()->getValue());
    }

    public function test_count_completed(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);

        $this->assertEquals(2, $this->repository->countCompleted()->getValue());
    }

    public function test_count_completed_returns_zero_when_none(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);
        $this->assertEquals(0, $this->repository->countCompleted()->getValue());
    }

    public function test_count_failed(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);

        $this->assertEquals(2, $this->repository->countFailed()->getValue());
    }

    public function test_count_failed_returns_zero_when_none(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);
        $this->assertEquals(0, $this->repository->countFailed()->getValue());
    }

    public function test_count_canceled(): void
    {
        $this->createCanceledTask();
        $this->createCanceledTask();
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);

        $this->assertEquals(2, $this->repository->countCanceled()->getValue());
    }

    public function test_count_canceled_returns_zero_when_none(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);
        $this->assertEquals(0, $this->repository->countCanceled()->getValue());
    }

    // ==================== TESTS CREATE ====================

    public function test_create_persists_task(): void
    {
        $id = $this->generateUuid();
        $scheduledAt = now()->addDays(1);
        $alias = $this->generateUuid();

        $record = $this->createTaskRecord(
            alias: $alias,
            id: $id,
            status: UniqueTaskStatus::PENDING,
            scheduledAt: $scheduledAt,
            gracePeriodSeconds: 43200,
            attempts: 0,
            maxAttempts: 5
        );

        $model = $this->repository->create($record);

        $this->assertInstanceOf(UniqueTask::class, $model);
        $this->assertEquals('unique@'.$alias, $model->getAlias()->getValue());
        $this->assertEquals(43200, $model->getGracePeriodSeconds());
        $this->assertEquals(5, $model->getMaxAttempts()->getValue());
        $this->assertEquals(UniqueTaskStatus::PENDING, $model->getStatus());

        $found = $this->repository->findById(new UuidVO($id));
        $this->assertNotNull($found);
    }

    // ==================== TESTS DELETE ====================

    public function test_delete_soft_deletes_task(): void
    {
        $id = $this->generateUuid();
        $model = $this->createAndSaveTask(null, $id, UniqueTaskStatus::PENDING);

        $model->delete();

        $found = $this->repository->findById(new UuidVO($id));
        $this->assertNull($found);

        $withTrashed = UniqueTask::withTrashed()->where('id', $id)->first();
        $this->assertNotNull($withTrashed);
        $this->assertNotNull($withTrashed->deleted_at);
    }

    // ==================== TESTS FILTERS ====================

    public function test_apply_filters_with_alias(): void
    {
        $alias = $this->generateUuid();
        $this->createAndSaveTask($alias, null, UniqueTaskStatus::PENDING);

        $filters = UniqueTaskFiltersRecord::from([
            'alias' => $this->createAliasVO($alias),
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals('unique@'.$alias, $results->first()->getAlias()->getValue());
    }

    public function test_apply_filters_with_status(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::COMPLETED);

        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals(UniqueTaskStatus::PENDING, $results->first()->getStatus());
    }

    public function test_apply_filters_with_canceled_status(): void
    {
        $this->createCanceledTask();
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING);

        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::CANCELED,
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals(UniqueTaskStatus::CANCELED, $results->first()->getStatus());
    }

    public function test_apply_filters_with_attempts(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, null, 86400, 2);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, null, 86400, 5);

        $filters = UniqueTaskFiltersRecord::from([
            'attempts' => new CounterVO(2),
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results->first()->getAttempts()->getValue());
    }

    public function test_apply_filters_with_max_attempts(): void
    {
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, null, 86400, 0, 3);
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, null, 86400, 0, 5);

        $filters = UniqueTaskFiltersRecord::from([
            'max_attempts' => new MaxFailedAttemptsVO(5),
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals(5, $results->first()->getMaxAttempts()->getValue());
    }

    public function test_apply_filters_with_scheduled_at_range(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subDays(5));
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->subDays(2));
        $this->createAndSaveTask(null, null, UniqueTaskStatus::PENDING, $now->copy()->addDays(2));

        $filters = UniqueTaskFiltersRecord::from([
            'scheduled_at_from' => new Iso8601DateTimeVO($now->copy()->subDays(3)->format('Y-m-d\TH:i:sP')),
            'scheduled_at_to' => new Iso8601DateTimeVO($now->copy()->addDays(1)->format('Y-m-d\TH:i:sP')),
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
    }

    public function test_apply_filters_with_id(): void
    {
        $id = $this->generateUuid();
        $this->createAndSaveTask(null, $id, UniqueTaskStatus::PENDING);

        $filters = UniqueTaskFiltersRecord::from([
            'id' => new UuidVO($id),
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals($id, $results->first()->getId()->getValue());
    }
}
