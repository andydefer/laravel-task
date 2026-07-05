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
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\RecurringTaskFiltersRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskTypeVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

final class RecurringTaskRepositoryTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private RecurringTaskRepository $repository;

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
        $this->repository = new RecurringTaskRepository($this->debugRepository, $this->logger);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $fs = new FileSystemService;
        if ($fs->isDirectory($this->logPath)) {
            $fs->deleteDirectory($this->logPath);
        }

        Carbon::setTestNow(null);
    }

    // ==================== HELPERS ====================

    private function generateUuid(): string
    {
        return Uuid::uuid4()->toString();
    }

    private function createAliasVO(?string $uuid = null): TaskAliasVO
    {
        $uuid = $uuid ?? $this->generateUuid();

        return new TaskAliasVO(
            type: new TaskTypeVO('recurring'),
            uuid: $uuid
        );
    }

    private function createFqcnVO(string $fqcn = TestRecurringTask::class): TaskFqcnVO
    {
        return new TaskFqcnVO($fqcn);
    }

    private function formatDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }

    private function createAndSaveTask(
        ?string $alias = null,
        RecurringTaskStatus $status = RecurringTaskStatus::WAITING,
        ?\DateTimeInterface $startAt = null,
        ?\DateTimeInterface $endAt = null,
        int $intervalSeconds = 3600,
        ?\DateTimeInterface $lastRunAt = null,
        string $fqcn = TestRecurringTask::class,
        int $failedAttempts = 0,
        int $maxFailedAttempts = 3
    ): RecurringTaskRecord {
        $alias = $alias ?? $this->generateUuid();
        $startAt = $startAt ?? Carbon::now()->addHours(2);
        $endAt = $endAt ?? Carbon::now()->addDays(7);
        $id = $this->generateUuid();

        $task = RecurringTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $this->createAliasVO($alias),
            'fqcn' => $this->createFqcnVO($fqcn),
            'payload' => ['test' => 'recurring'],
            'interval_seconds' => new DurationVO($intervalSeconds),
            'start_at' => new Iso8601DateTimeVO($this->formatDate($startAt)),
            'end_at' => new Iso8601DateTimeVO($this->formatDate($endAt)),
            'status' => $status,
            'last_run_at' => $lastRunAt ? new Iso8601DateTimeVO($this->formatDate($lastRunAt)) : null,
            'failed_attempts' => new CounterVO($failedAttempts),
            'max_failed_attempts' => new MaxFailedAttemptsVO($maxFailedAttempts),
        ]);

        $this->repository->create($task);

        return $task;
    }

    private function createCancelledTask(?string $alias = null): RecurringTaskRecord
    {
        $alias = $alias ?? $this->generateUuid();
        $id = $this->generateUuid();

        $task = RecurringTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $this->createAliasVO($alias),
            'fqcn' => $this->createFqcnVO(),
            'payload' => ['test' => 'recurring'],
            'interval_seconds' => new DurationVO(3600),
            'start_at' => new Iso8601DateTimeVO($this->formatDate(Carbon::now()->addHours(2))),
            'end_at' => new Iso8601DateTimeVO($this->formatDate(Carbon::now()->addDays(7))),
            'status' => RecurringTaskStatus::CANCELED,
            'cancelled_at' => new Iso8601DateTimeVO($this->formatDate(Carbon::now())),
        ]);

        $this->repository->create($task);

        return $task;
    }

    // ==================== TEST findByAlias ====================

    public function test_find_by_alias_returns_model(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();
        $this->createAndSaveTask($alias, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $found = $this->repository->findByAlias($this->createAliasVO($alias));

        $this->assertNotNull($found);
        $this->assertInstanceOf(RecurringTask::class, $found);
        $this->assertEquals('recurring@'.$alias, $found->getAlias()->getValue());
        $this->assertEquals(RecurringTaskStatus::WAITING, $found->getStatus());
    }

    public function test_find_by_alias_returns_null_when_not_found(): void
    {
        $alias = $this->generateUuid();
        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNull($found);
    }

    public function test_find_by_alias_returns_null_when_deleted(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();
        $this->createAndSaveTask($alias, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
        $model = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->repository->delete($model->getId()->getValue());

        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNull($found);
    }

    // ==================== TESTS FINDERS ====================

    public function test_find_waiting_returns_collection(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(3));
        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING, $frozenNow->copy()->addHours(2));

        $waiting = $this->repository->findWaiting(new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $waiting);
        $this->assertCount(2, $waiting);

        foreach ($waiting as $task) {
            $this->assertInstanceOf(RecurringTask::class, $task);
            $this->assertEquals(RecurringTaskStatus::WAITING, $task->getStatus());
        }
    }

    public function test_find_waiting_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(3));
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(4));

        $waiting = $this->repository->findWaiting(new LimitVO(2));

        $this->assertInstanceOf(Collection::class, $waiting);
        $this->assertCount(2, $waiting);
    }

    public function test_find_waiting_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING, $frozenNow->copy()->addHours(2));

        $waiting = $this->repository->findWaiting(new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $waiting);
        $this->assertCount(0, $waiting);
    }

    public function test_find_playing_returns_collection(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $playing = $this->repository->findPlaying(new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $playing);
        $this->assertCount(2, $playing);

        foreach ($playing as $task) {
            $this->assertInstanceOf(RecurringTask::class, $task);
            $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
        }
    }

    public function test_find_playing_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING);

        $playing = $this->repository->findPlaying(new LimitVO(2));

        $this->assertInstanceOf(Collection::class, $playing);
        $this->assertCount(2, $playing);
    }

    public function test_find_playing_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $playing = $this->repository->findPlaying(new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $playing);
        $this->assertCount(0, $playing);
    }

    public function test_find_paused_returns_collection(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask(null, RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $paused = $this->repository->findPaused(new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $paused);
        $this->assertCount(2, $paused);

        foreach ($paused as $task) {
            $this->assertInstanceOf(RecurringTask::class, $task);
            $this->assertEquals(RecurringTaskStatus::PAUSED, $task->getStatus());
        }
    }

    public function test_find_paused_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask(null, RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask(null, RecurringTaskStatus::PAUSED);

        $paused = $this->repository->findPaused(new LimitVO(2));

        $this->assertInstanceOf(Collection::class, $paused);
        $this->assertCount(2, $paused);
    }

    public function test_find_paused_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $paused = $this->repository->findPaused(new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $paused);
        $this->assertCount(0, $paused);
    }

    public function test_find_finished_returns_collection(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask(null, RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $finished = $this->repository->findFinished(new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $finished);
        $this->assertCount(2, $finished);

        foreach ($finished as $task) {
            $this->assertInstanceOf(RecurringTask::class, $task);
            $this->assertEquals(RecurringTaskStatus::FINISHED, $task->getStatus());
        }
    }

    public function test_find_finished_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask(null, RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask(null, RecurringTaskStatus::FINISHED);

        $finished = $this->repository->findFinished(new LimitVO(2));

        $this->assertInstanceOf(Collection::class, $finished);
        $this->assertCount(2, $finished);
    }

    public function test_find_finished_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $finished = $this->repository->findFinished(new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $finished);
        $this->assertCount(0, $finished);
    }

    // ==================== TESTS FIND CANCELED ====================

    public function test_find_canceled_returns_collection(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createCancelledTask();
        $this->createCancelledTask();
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
        $this->createAndSaveTask(null, RecurringTaskStatus::FINISHED);

        $canceled = $this->repository->findCanceled(new LimitVO(10));

        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(2, $canceled);

        foreach ($canceled as $task) {
            $this->assertInstanceOf(RecurringTask::class, $task);
            $this->assertEquals(RecurringTaskStatus::CANCELED, $task->getStatus());
        }
    }

    public function test_find_canceled_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createCancelledTask();
        $this->createCancelledTask();
        $this->createCancelledTask();

        $canceled = $this->repository->findCanceled(new LimitVO(2));

        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(2, $canceled);
    }

    public function test_find_canceled_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $canceled = $this->repository->findCanceled(new LimitVO(10));
        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(0, $canceled);
    }

    // ==================== TESTS READY TO RUN ====================

    public function test_find_ready_to_run_returns_result_record(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        // ✅ Tâches avec start_at dans le PASSÉ → seront transformées en PLAYING
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->subHours(2));
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->subHours(1));

        // ✅ Tâche déjà PLAYING
        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING, $frozenNow->copy()->subHours(2));

        // ❌ Tâche avec start_at dans le FUTUR → restera WAITING
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $result = $this->repository->findReadyToRun(new Iso8601DateTimeVO($this->formatDate($frozenNow)), new LimitVO(10));

        // ✅ 2 WAITING transformées en PLAYING + 1 déjà PLAYING = 3
        $this->assertCount(3, $result->tasks);

        $this->assertEquals(2, $result->fresh_state->waiting_to_playing->getValue());
        $this->assertEquals(0, $result->fresh_state->playing_to_finished->getValue());
    }

    public function test_find_ready_to_run_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        for ($i = 1; $i <= 5; $i++) {
            $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->subHours(2));
        }

        $result = $this->repository->findReadyToRun(new Iso8601DateTimeVO($this->formatDate($frozenNow)), new LimitVO(3));

        // ✅ 5 WAITING transformées en PLAYING, limité à 3
        $this->assertCount(3, $result->tasks);
        $this->assertEquals(5, $result->fresh_state->waiting_to_playing->getValue());
    }

    public function test_find_ready_to_run_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        // ✅ Toutes les tâches ont start_at dans le FUTUR → aucune transformée
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(3));

        $result = $this->repository->findReadyToRun(new Iso8601DateTimeVO($this->formatDate($frozenNow)), new LimitVO(10));

        $this->assertInstanceOf(RecurringTaskRecordCollection::class, $result->tasks);
        $this->assertCount(0, $result->tasks);
        $this->assertEquals(0, $result->fresh_state->waiting_to_playing->getValue());
    }

    public function test_find_ready_to_run_counts_finished_tasks(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        // ✅ Tâche PLAYING avec end_at dans le PASSÉ → sera FINISHED
        $this->createAndSaveTask(
            null,
            RecurringTaskStatus::PLAYING,
            $frozenNow->copy()->subDays(7),
            $frozenNow->copy()->subHours(1)
        );

        // ✅ Tâche WAITING avec start_at dans le PASSÉ → sera PLAYING
        $this->createAndSaveTask(
            null,
            RecurringTaskStatus::WAITING,
            $frozenNow->copy()->subHours(2)
        );

        $result = $this->repository->findReadyToRun(new Iso8601DateTimeVO($this->formatDate($frozenNow)), new LimitVO(10));

        $this->assertEquals(1, $result->fresh_state->playing_to_finished->getValue());
        $this->assertEquals(1, $result->fresh_state->waiting_to_playing->getValue());
        $this->assertCount(1, $result->tasks);
    }

    // ==================== TESTS MOVES ====================

    public function test_move_to_playing_updates_status(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();
        $task = $this->createAndSaveTask($alias, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $result = $this->repository->moveToPlaying($task);
        $this->assertTrue($result);

        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $found->getStatus());
    }

    public function test_move_to_playing_returns_false_when_task_not_found(): void
    {
        $task = RecurringTaskRecord::from([
            'alias' => $this->createAliasVO($this->generateUuid()),
            'fqcn' => $this->createFqcnVO(),
            'payload' => ['test' => 'recurring'],
            'interval_seconds' => new DurationVO(3600),
        ]);

        $result = $this->repository->moveToPlaying($task);
        $this->assertFalse($result);
    }

    public function test_move_to_paused_updates_status(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();
        $task = $this->createAndSaveTask($alias, RecurringTaskStatus::PLAYING);

        $result = $this->repository->moveToPaused($task);
        $this->assertTrue($result);

        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::PAUSED, $found->getStatus());
    }

    public function test_move_to_paused_returns_false_when_task_not_found(): void
    {
        $task = RecurringTaskRecord::from([
            'alias' => $this->createAliasVO($this->generateUuid()),
            'fqcn' => $this->createFqcnVO(),
            'payload' => ['test' => 'recurring'],
            'interval_seconds' => new DurationVO(3600),
        ]);

        $result = $this->repository->moveToPaused($task);
        $this->assertFalse($result);
    }

    public function test_move_to_waiting_updates_status(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();
        $task = $this->createAndSaveTask($alias, RecurringTaskStatus::PAUSED);

        $result = $this->repository->moveToWaiting($task);
        $this->assertTrue($result);

        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::WAITING, $found->getStatus());
    }

    public function test_move_to_waiting_returns_false_when_task_not_found(): void
    {
        $task = RecurringTaskRecord::from([
            'alias' => $this->createAliasVO($this->generateUuid()),
            'fqcn' => $this->createFqcnVO(),
            'payload' => ['test' => 'recurring'],
            'interval_seconds' => new DurationVO(3600),
        ]);

        $result = $this->repository->moveToWaiting($task);
        $this->assertFalse($result);
    }

    public function test_move_to_finished_updates_status_and_sets_finished_at(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();
        $task = $this->createAndSaveTask($alias, RecurringTaskStatus::PLAYING);

        $result = $this->repository->moveToFinished($task);
        $this->assertTrue($result);

        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::FINISHED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
    }

    public function test_move_to_finished_returns_false_when_task_not_found(): void
    {
        $task = RecurringTaskRecord::from([
            'alias' => $this->createAliasVO($this->generateUuid()),
            'fqcn' => $this->createFqcnVO(),
            'payload' => ['test' => 'recurring'],
            'interval_seconds' => new DurationVO(3600),
        ]);

        $result = $this->repository->moveToFinished($task);
        $this->assertFalse($result);
    }

    public function test_move_to_canceled_updates_status_and_sets_cancelled_at(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();
        $task = $this->createAndSaveTask($alias, RecurringTaskStatus::PLAYING);

        $result = $this->repository->moveToCanceled($task);
        $this->assertTrue($result);

        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
        $this->assertNotNull($found->getCancelledAt());
    }

    public function test_move_to_canceled_returns_false_when_task_not_found(): void
    {
        $task = RecurringTaskRecord::from([
            'alias' => $this->createAliasVO($this->generateUuid()),
            'fqcn' => $this->createFqcnVO(),
            'payload' => ['test' => 'recurring'],
            'interval_seconds' => new DurationVO(3600),
        ]);

        $result = $this->repository->moveToCanceled($task);
        $this->assertFalse($result);
    }

    // ==================== TESTS UPDATE AFTER RUN ====================

    public function test_update_after_run_success_updates_last_run_at_and_adds_debug(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();

        // ✅ Créer une tâche PLAYING
        $task = $this->createAndSaveTask($alias, RecurringTaskStatus::PLAYING);

        // ✅ Vérifier que la tâche existe
        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNotNull($found, 'Task should exist before updateAfterRun');

        $result = $this->repository->updateAfterRun($task, true);
        $this->assertTrue($result);

        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $found->getStatus());
        $this->assertNotNull($found->getLastRunAt());

        $debugs = $this->debugRepository->findByAlias($this->createAliasVO($alias));
        $this->assertCount(1, $debugs);

        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugs->first()->getStatus()->value);
        $this->assertEquals('Recurring task executed successfully', $debugData->info);
    }

    public function test_update_after_run_failure_updates_last_run_at_and_adds_debug_with_error(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();

        // ✅ Créer une tâche PLAYING
        $task = $this->createAndSaveTask($alias, RecurringTaskStatus::PLAYING);

        // ✅ Vérifier que la tâche existe
        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNotNull($found, 'Task should exist before updateAfterRun');

        $result = $this->repository->updateAfterRun($task, false, new DescriptionVO('Test error'));
        $this->assertTrue($result);

        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $found->getStatus());
        $this->assertNotNull($found->getLastRunAt());

        $debugs = $this->debugRepository->findByAlias($this->createAliasVO($alias));
        $this->assertCount(1, $debugs);

        $debugData = $debugs->first()->getData();
        $this->assertEquals('failed', $debugs->first()->getStatus()->value);
        $this->assertEquals('Test error', $debugData->info);
    }

    public function test_update_after_run_returns_false_when_task_not_found(): void
    {
        $task = RecurringTaskRecord::from([
            'alias' => $this->createAliasVO($this->generateUuid()),
            'fqcn' => $this->createFqcnVO(),
            'payload' => ['test' => 'recurring'],
            'interval_seconds' => new DurationVO(3600),
        ]);

        $result = $this->repository->updateAfterRun($task, true);
        $this->assertFalse($result);
    }

    // ==================== TESTS COUNTS ====================

    public function test_count_waiting(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(3));
        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING);

        $this->assertEquals(2, $this->repository->countWaiting()->getValue());
    }

    public function test_count_playing(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $this->assertEquals(2, $this->repository->countPlaying()->getValue());
    }

    public function test_count_paused(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask(null, RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $this->assertEquals(2, $this->repository->countPaused()->getValue());
    }

    public function test_count_finished(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask(null, RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $this->assertEquals(2, $this->repository->countFinished()->getValue());
    }

    public function test_count_canceled(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createCancelledTask();
        $this->createCancelledTask();
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $this->assertEquals(2, $this->repository->countCanceled()->getValue());
    }

    // ==================== TESTS CREATE ====================

    public function test_create_persists_task(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();
        $startAt = $frozenNow->copy()->addDays(1);
        $endAt = $frozenNow->copy()->addDays(8);

        $task = RecurringTaskRecord::from([
            'id' => new UuidVO($this->generateUuid()),
            'alias' => $this->createAliasVO($alias),
            'fqcn' => $this->createFqcnVO(),
            'payload' => ['test' => 'create'],
            'interval_seconds' => new DurationVO(7200),
            'start_at' => new Iso8601DateTimeVO($this->formatDate($startAt)),
            'end_at' => new Iso8601DateTimeVO($this->formatDate($endAt)),
            'status' => RecurringTaskStatus::WAITING,
        ]);

        $this->repository->create($task);

        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNotNull($found);
        $this->assertEquals('recurring@'.$alias, $found->getAlias()->getValue());
        $this->assertEquals(7200, $found->getIntervalSeconds()->getValue());
        $this->assertEquals(RecurringTaskStatus::WAITING, $found->getStatus());
    }

    // ==================== TESTS DELETE ====================

    public function test_delete_soft_deletes_task(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias = $this->generateUuid();
        $this->createAndSaveTask($alias, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $model = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->repository->delete($model->getId()->getValue());

        $found = $this->repository->findByAlias($this->createAliasVO($alias));
        $this->assertNull($found);

        $withTrashed = $this->repository->findWithTrashed($model->getId()->getValue());
        $this->assertNotNull($withTrashed);
        $this->assertNotNull($withTrashed->deleted_at);
    }

    // ==================== TESTS FILTERS ====================

    public function test_apply_filters_with_alias(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $alias1 = $this->generateUuid();
        $alias2 = $this->generateUuid();
        $this->createAndSaveTask($alias1, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
        $this->createAndSaveTask($alias2, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(3));

        $filters = RecurringTaskFiltersRecord::from([
            'alias' => $this->createAliasVO($alias1),
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals('recurring@'.$alias1, $results->first()->getAlias()->getValue());
    }

    public function test_apply_filters_with_status(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
        $this->createAndSaveTask(null, RecurringTaskStatus::PLAYING);

        $filters = RecurringTaskFiltersRecord::from([
            'status' => RecurringTaskStatus::WAITING,
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals(RecurringTaskStatus::WAITING, $results->first()->getStatus());
    }

    public function test_apply_filters_with_canceled_status(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->createCancelledTask();
        $this->createAndSaveTask(null, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

        $filters = RecurringTaskFiltersRecord::from([
            'status' => RecurringTaskStatus::CANCELED,
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $results->first()->getStatus());
    }
}
