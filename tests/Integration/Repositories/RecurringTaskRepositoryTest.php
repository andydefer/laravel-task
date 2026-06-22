<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Repositories;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\RecurringTaskFiltersRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class RecurringTaskRepositoryTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private RecurringTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runDatabaseMigrations();

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new RecurringTaskRepository($this->debugRepository);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function formatDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }

    private function createAndSaveTask(
        string $alias,
        RecurringTaskStatus $status = RecurringTaskStatus::WAITING,
        ?\DateTimeInterface $startAt = null,
        ?\DateTimeInterface $endAt = null,
        int $intervalSeconds = 3600,
        ?\DateTimeInterface $lastRunAt = null,
        string $fqcn = 'TestRecurringTask'
    ): RecurringTaskRecord {
        $startAt = $startAt ?? now();
        $endAt = $endAt ?? now()->addDay();

        $task = new RecurringTaskRecord(
            alias: new TaskSignatureVO($alias),
            fqcn: $fqcn,
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO($intervalSeconds),
            start_at: new Iso8601DateTimeVO($this->formatDate($startAt)),
            end_at: new Iso8601DateTimeVO($this->formatDate($endAt)),
            status: $status,
            last_run_at: $lastRunAt ? new Iso8601DateTimeVO($this->formatDate($lastRunAt)) : null,
        );

        $this->repository->create($task);

        return $task;
    }

    private function createCancelledTask(string $alias): RecurringTaskRecord
    {
        $task = $this->createAndSaveTask($alias, RecurringTaskStatus::CANCELED);
        $model = $this->repository->findByAlias($alias);
        $model->update(['cancelled_at' => now()->toDateTimeString()]);

        return $task;
    }

    // ==================== TEST findByAlias ====================

    public function test_find_by_alias_returns_model(): void
    {
        $this->createAndSaveTask('test-find-by-alias', RecurringTaskStatus::WAITING);

        $found = $this->repository->findByAlias('test-find-by-alias');

        $this->assertNotNull($found);
        $this->assertInstanceOf(RecurringTask::class, $found);
        $this->assertEquals('test-find-by-alias', $found->getAlias()->getValue());
        $this->assertEquals(RecurringTaskStatus::WAITING, $found->getStatus());
    }

    public function test_find_by_alias_returns_null_when_not_found(): void
    {
        $found = $this->repository->findByAlias('non-existent');
        $this->assertNull($found);
    }

    public function test_find_by_alias_returns_null_when_deleted(): void
    {
        $this->createAndSaveTask('delete-test', RecurringTaskStatus::WAITING);
        $model = $this->repository->findByAlias('delete-test');
        $this->repository->delete($model->getId());

        $found = $this->repository->findByAlias('delete-test');
        $this->assertNull($found);
    }

    // ==================== TESTS FINDERS ====================

    public function test_find_waiting_returns_collection(): void
    {
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);
        $this->createAndSaveTask('waiting-2', RecurringTaskStatus::WAITING);
        $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING);

        $waiting = $this->repository->findWaiting();

        $this->assertInstanceOf(Collection::class, $waiting);
        $this->assertCount(2, $waiting);

        foreach ($waiting as $task) {
            $this->assertInstanceOf(RecurringTask::class, $task);
            $this->assertEquals(RecurringTaskStatus::WAITING, $task->getStatus());
        }
    }

    public function test_find_waiting_with_limit(): void
    {
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);
        $this->createAndSaveTask('waiting-2', RecurringTaskStatus::WAITING);
        $this->createAndSaveTask('waiting-3', RecurringTaskStatus::WAITING);

        $waiting = $this->repository->findWaiting(2);

        $this->assertInstanceOf(Collection::class, $waiting);
        $this->assertCount(2, $waiting);
    }

    public function test_find_waiting_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING);

        $waiting = $this->repository->findWaiting();
        $this->assertInstanceOf(Collection::class, $waiting);
        $this->assertCount(0, $waiting);
    }

    public function test_find_playing_returns_collection(): void
    {
        $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask('playing-2', RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $playing = $this->repository->findPlaying();

        $this->assertInstanceOf(Collection::class, $playing);
        $this->assertCount(2, $playing);

        foreach ($playing as $task) {
            $this->assertInstanceOf(RecurringTask::class, $task);
            $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
        }
    }

    public function test_find_playing_with_limit(): void
    {
        $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask('playing-2', RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask('playing-3', RecurringTaskStatus::PLAYING);

        $playing = $this->repository->findPlaying(2);

        $this->assertInstanceOf(Collection::class, $playing);
        $this->assertCount(2, $playing);
    }

    public function test_find_playing_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $playing = $this->repository->findPlaying();
        $this->assertInstanceOf(Collection::class, $playing);
        $this->assertCount(0, $playing);
    }

    public function test_find_paused_returns_collection(): void
    {
        $this->createAndSaveTask('paused-1', RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask('paused-2', RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $paused = $this->repository->findPaused();

        $this->assertInstanceOf(Collection::class, $paused);
        $this->assertCount(2, $paused);

        foreach ($paused as $task) {
            $this->assertInstanceOf(RecurringTask::class, $task);
            $this->assertEquals(RecurringTaskStatus::PAUSED, $task->getStatus());
        }
    }

    public function test_find_paused_with_limit(): void
    {
        $this->createAndSaveTask('paused-1', RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask('paused-2', RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask('paused-3', RecurringTaskStatus::PAUSED);

        $paused = $this->repository->findPaused(2);

        $this->assertInstanceOf(Collection::class, $paused);
        $this->assertCount(2, $paused);
    }

    public function test_find_paused_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $paused = $this->repository->findPaused();
        $this->assertInstanceOf(Collection::class, $paused);
        $this->assertCount(0, $paused);
    }

    public function test_find_finished_returns_collection(): void
    {
        $this->createAndSaveTask('finished-1', RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask('finished-2', RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $finished = $this->repository->findFinished();

        $this->assertInstanceOf(Collection::class, $finished);
        $this->assertCount(2, $finished);

        foreach ($finished as $task) {
            $this->assertInstanceOf(RecurringTask::class, $task);
            $this->assertEquals(RecurringTaskStatus::FINISHED, $task->getStatus());
        }
    }

    public function test_find_finished_with_limit(): void
    {
        $this->createAndSaveTask('finished-1', RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask('finished-2', RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask('finished-3', RecurringTaskStatus::FINISHED);

        $finished = $this->repository->findFinished(2);

        $this->assertInstanceOf(Collection::class, $finished);
        $this->assertCount(2, $finished);
    }

    public function test_find_finished_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $finished = $this->repository->findFinished();
        $this->assertInstanceOf(Collection::class, $finished);
        $this->assertCount(0, $finished);
    }

    // ==================== TESTS FIND CANCELED ====================

    public function test_find_canceled_returns_collection(): void
    {
        $this->createCancelledTask('canceled-1');
        $this->createCancelledTask('canceled-2');
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);
        $this->createAndSaveTask('finished-1', RecurringTaskStatus::FINISHED);

        $canceled = $this->repository->findCanceled();

        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(2, $canceled);

        foreach ($canceled as $task) {
            $this->assertInstanceOf(RecurringTask::class, $task);
            $this->assertEquals(RecurringTaskStatus::CANCELED, $task->getStatus());
        }
    }

    public function test_find_canceled_with_limit(): void
    {
        $this->createCancelledTask('canceled-1');
        $this->createCancelledTask('canceled-2');
        $this->createCancelledTask('canceled-3');

        $canceled = $this->repository->findCanceled(2);

        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(2, $canceled);
    }

    public function test_find_canceled_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $canceled = $this->repository->findCanceled();
        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(0, $canceled);
    }

    // ==================== TESTS READY TO RUN ====================

    public function test_find_ready_to_run_returns_collection(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask('ready-1', RecurringTaskStatus::WAITING, $now->copy()->subHours(2), $now->copy()->addDays(1));
        $this->createAndSaveTask('ready-2', RecurringTaskStatus::WAITING, $now->copy(), $now->copy()->addDays(1));
        $this->createAndSaveTask('not-ready-1', RecurringTaskStatus::WAITING, $now->copy()->addHours(2), $now->copy()->addDays(1));
        $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING, $now->copy()->subHours(2), $now->copy()->addDays(1));

        $ready = $this->repository->findReadyToRun($now->toISOString());
        $this->assertCount(2, $ready);
    }

    public function test_find_ready_to_run_with_limit(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask('ready-1', RecurringTaskStatus::WAITING, $now->copy()->subHours(2), $now->copy()->addDays(1));
        $this->createAndSaveTask('ready-2', RecurringTaskStatus::WAITING, $now->copy()->subHours(1), $now->copy()->addDays(1));
        $this->createAndSaveTask('ready-3', RecurringTaskStatus::WAITING, $now->copy(), $now->copy()->addDays(1));

        $ready = $this->repository->findReadyToRun($now->toISOString(), 2);
        $this->assertCount(2, $ready);
    }

    public function test_find_ready_to_run_returns_empty_collection_when_none(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask('not-ready-1', RecurringTaskStatus::WAITING, $now->copy()->addHours(2), $now->copy()->addDays(1));

        $ready = $this->repository->findReadyToRun($now->toISOString());
        $this->assertInstanceOf(Collection::class, $ready);
        $this->assertCount(0, $ready);
    }

    public function test_find_expired_returns_collection(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask('expired-1', RecurringTaskStatus::PLAYING, $now->copy()->subDays(2), $now->copy()->subDay());
        $this->createAndSaveTask('expired-2', RecurringTaskStatus::PLAYING, $now->copy()->subDays(2), $now->copy());
        $this->createAndSaveTask('not-expired-1', RecurringTaskStatus::PLAYING, $now->copy()->subDays(2), $now->copy()->addDay());
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $now->copy()->subDays(2), $now->copy()->subDay());

        $expired = $this->repository->findExpired($this->formatDate($now));

        $this->assertInstanceOf(Collection::class, $expired);
        $this->assertCount(2, $expired);

        $aliases = $expired->map(fn ($task) => $task->getAlias()->getValue())->toArray();
        $this->assertContains('expired-1', $aliases);
        $this->assertContains('expired-2', $aliases);
        $this->assertNotContains('not-expired-1', $aliases);
        $this->assertNotContains('waiting-1', $aliases);
    }

    public function test_find_expired_with_limit(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask('expired-1', RecurringTaskStatus::PLAYING, $now->copy()->subDays(2), $now->copy()->subDay());
        $this->createAndSaveTask('expired-2', RecurringTaskStatus::PLAYING, $now->copy()->subDays(1), $now->copy()->subDay());
        $this->createAndSaveTask('not-expired-1', RecurringTaskStatus::PLAYING, $now->copy()->subDays(2), $now->copy()->addDay());

        $expired = $this->repository->findExpired($this->formatDate($now), 1);
        $this->assertCount(1, $expired);
    }

    public function test_find_expired_returns_empty_collection_when_none(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask('not-expired-1', RecurringTaskStatus::PLAYING, $now->copy()->subDays(2), $now->copy()->addDay());

        $expired = $this->repository->findExpired($this->formatDate($now));
        $this->assertInstanceOf(Collection::class, $expired);
        $this->assertCount(0, $expired);
    }

    // ==================== TESTS CANCELLED COUNTS ====================

    public function test_count_cancelled_returns_zero_when_no_cancelled_tasks(): void
    {
        $this->createAndSaveTask('cancelled-1', RecurringTaskStatus::WAITING);
        $this->createAndSaveTask('cancelled-2', RecurringTaskStatus::PLAYING);

        $this->assertEquals(0, $this->repository->countCanceled());
    }

    public function test_count_cancelled_returns_count_of_cancelled_tasks(): void
    {
        $this->createCancelledTask('cancelled-1');
        $this->createCancelledTask('cancelled-2');
        $this->createAndSaveTask('not-cancelled-1', RecurringTaskStatus::WAITING);

        $this->assertEquals(2, $this->repository->countCanceled());
    }

    public function test_find_finished_excludes_cancelled_tasks(): void
    {
        $this->createCancelledTask('cancelled-1');
        $this->createAndSaveTask('finished-1', RecurringTaskStatus::FINISHED);

        $finished = $this->repository->findFinished();

        $this->assertCount(1, $finished);

        $aliases = $finished->map(fn ($task) => $task->getAlias()->getValue())->toArray();
        $this->assertContains('finished-1', $aliases);
        $this->assertNotContains('cancelled-1', $aliases);
    }

    // ==================== TESTS MOVES ====================

    public function test_move_to_playing_updates_status(): void
    {
        $task = $this->createAndSaveTask('test-move-playing', RecurringTaskStatus::WAITING);

        $this->repository->moveToPlaying($task);

        $found = $this->repository->findByAlias('test-move-playing');
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $found->getStatus());
    }

    public function test_move_to_playing_throws_exception_when_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found: non-existent');

        $task = new RecurringTaskRecord(
            alias: new TaskSignatureVO('non-existent'),
            fqcn: 'TestRecurringTask',
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO(3600),
        );

        $this->repository->moveToPlaying($task);
    }

    public function test_move_to_paused_updates_status(): void
    {
        $task = $this->createAndSaveTask('test-move-paused', RecurringTaskStatus::PLAYING);

        $this->repository->moveToPaused($task);

        $found = $this->repository->findByAlias('test-move-paused');
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::PAUSED, $found->getStatus());
    }

    public function test_move_to_paused_throws_exception_when_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found: non-existent');

        $task = new RecurringTaskRecord(
            alias: new TaskSignatureVO('non-existent'),
            fqcn: 'TestRecurringTask',
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO(3600),
        );

        $this->repository->moveToPaused($task);
    }

    public function test_move_to_waiting_updates_status(): void
    {
        $task = $this->createAndSaveTask('test-move-waiting', RecurringTaskStatus::PAUSED);

        $this->repository->moveToWaiting($task);

        $found = $this->repository->findByAlias('test-move-waiting');
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::WAITING, $found->getStatus());
    }

    public function test_move_to_waiting_throws_exception_when_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found: non-existent');

        $task = new RecurringTaskRecord(
            alias: new TaskSignatureVO('non-existent'),
            fqcn: 'TestRecurringTask',
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO(3600),
        );

        $this->repository->moveToWaiting($task);
    }

    public function test_move_to_finished_updates_status_and_sets_finished_at(): void
    {
        $task = $this->createAndSaveTask('test-move-finished', RecurringTaskStatus::PLAYING);

        $this->repository->moveToFinished($task);

        $found = $this->repository->findByAlias('test-move-finished');
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::FINISHED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
    }

    public function test_move_to_finished_throws_exception_when_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found: non-existent');

        $task = new RecurringTaskRecord(
            alias: new TaskSignatureVO('non-existent'),
            fqcn: 'TestRecurringTask',
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO(3600),
        );

        $this->repository->moveToFinished($task);
    }

    // ==================== TEST MOVE TO CANCELED ====================

    public function test_move_to_canceled_updates_status_and_sets_cancelled_at(): void
    {
        $task = $this->createAndSaveTask('test-move-canceled', RecurringTaskStatus::PLAYING);

        $this->repository->moveToCanceled($task);

        $found = $this->repository->findByAlias('test-move-canceled');
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
        $this->assertNotNull($found->getCancelledAt());
    }

    public function test_move_to_canceled_throws_exception_when_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found: non-existent');

        $task = new RecurringTaskRecord(
            alias: new TaskSignatureVO('non-existent'),
            fqcn: 'TestRecurringTask',
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO(3600),
        );

        $this->repository->moveToCanceled($task);
    }

    // ==================== TESTS UPDATE AFTER RUN ====================

    public function test_update_after_run_success_updates_last_run_at_and_adds_debug(): void
    {
        $task = $this->createAndSaveTask('test-update-success', RecurringTaskStatus::PLAYING);

        $this->repository->updateAfterRun($task, true);

        $found = $this->repository->findByAlias('test-update-success');
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $found->getStatus());
        $this->assertNotNull($found->getLastRunAt());

        $debugs = $this->debugRepository->findByTask('recurring', 'test-update-success');
        $this->assertCount(1, $debugs);

        $debugData = $debugs[0]->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Recurring task executed successfully', $debugData->info);
    }

    public function test_update_after_run_failure_updates_last_run_at_and_adds_debug_with_error(): void
    {
        $task = $this->createAndSaveTask('test-update-failure', RecurringTaskStatus::PLAYING);

        $errorMessage = 'Test error message';
        $this->repository->updateAfterRun($task, false, $errorMessage);

        $found = $this->repository->findByAlias('test-update-failure');
        $this->assertNotNull($found);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $found->getStatus());
        $this->assertNotNull($found->getLastRunAt());

        $debugs = $this->debugRepository->findByTask('recurring', 'test-update-failure');
        $this->assertCount(1, $debugs);

        $debugData = $debugs[0]->getData();
        $this->assertEquals('failed', $debugData->status);
        $this->assertEquals($errorMessage, $debugData->info);
    }

    public function test_update_after_run_throws_exception_when_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found: non-existent');

        $task = new RecurringTaskRecord(
            alias: new TaskSignatureVO('non-existent'),
            fqcn: 'TestRecurringTask',
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO(3600),
        );

        $this->repository->updateAfterRun($task, true);
    }

    // ==================== TESTS COUNTS ====================

    public function test_count_waiting(): void
    {
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);
        $this->createAndSaveTask('waiting-2', RecurringTaskStatus::WAITING);
        $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING);

        $this->assertEquals(2, $this->repository->countWaiting());
    }

    public function test_count_playing(): void
    {
        $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask('playing-2', RecurringTaskStatus::PLAYING);
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $this->assertEquals(2, $this->repository->countPlaying());
    }

    public function test_count_paused(): void
    {
        $this->createAndSaveTask('paused-1', RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask('paused-2', RecurringTaskStatus::PAUSED);
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $this->assertEquals(2, $this->repository->countPaused());
    }

    public function test_count_finished(): void
    {
        $this->createAndSaveTask('finished-1', RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask('finished-2', RecurringTaskStatus::FINISHED);
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $this->assertEquals(2, $this->repository->countFinished());
    }

    public function test_count_canceled(): void
    {
        $this->createCancelledTask('canceled-1');
        $this->createCancelledTask('canceled-2');
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $this->assertEquals(2, $this->repository->countCanceled());
    }

    // ==================== TESTS CREATE ====================

    public function test_create_persists_task(): void
    {
        $alias = 'create-test';
        $startAt = now()->addDays(1);
        $endAt = now()->addDays(8);

        $task = new RecurringTaskRecord(
            alias: new TaskSignatureVO($alias),
            fqcn: 'TestRecurringTask',
            payload: StrictDataObject::from(['test' => 'create']),
            interval_seconds: new CounterVO(7200),
            start_at: new Iso8601DateTimeVO($this->formatDate($startAt)),
            end_at: new Iso8601DateTimeVO($this->formatDate($endAt)),
            status: RecurringTaskStatus::WAITING,
        );

        $this->repository->create($task);

        $found = $this->repository->findByAlias($alias);
        $this->assertNotNull($found);
        $this->assertEquals('create-test', $found->getAlias()->getValue());
        $this->assertEquals(7200, $found->getIntervalSeconds()->value);
        $this->assertEquals(RecurringTaskStatus::WAITING, $found->getStatus());
    }

    // ==================== TESTS UPDATE ====================

    public function test_update_updates_task(): void
    {
        $alias = 'update-test';
        $task = $this->createAndSaveTask($alias, RecurringTaskStatus::WAITING);
        $model = $this->repository->findByAlias($alias);

        $updated = new RecurringTaskRecord(
            alias: new TaskSignatureVO($alias),
            fqcn: 'UpdatedTask',
            payload: StrictDataObject::from(['updated' => true]),
            interval_seconds: new CounterVO(14400),
            start_at: new Iso8601DateTimeVO($this->formatDate(now()->addDays(2))),
            end_at: new Iso8601DateTimeVO($this->formatDate(now()->addDays(9))),
            status: RecurringTaskStatus::PLAYING,
            last_run_at: new Iso8601DateTimeVO($this->formatDate(now())),
        );

        $this->repository->update($model->getId(), $updated);

        $found = $this->repository->findByAlias($alias);
        $this->assertNotNull($found);
        $this->assertEquals('UpdatedTask', $found->getFqcn());
        $this->assertEquals(14400, $found->getIntervalSeconds()->value);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $found->getStatus());
        $this->assertNotNull($found->getLastRunAt());
    }

    // ==================== TESTS DELETE ====================

    public function test_delete_soft_deletes_task(): void
    {
        $alias = 'delete-test';
        $this->createAndSaveTask($alias, RecurringTaskStatus::WAITING);

        $model = $this->repository->findByAlias($alias);
        $this->repository->delete($model->getId());

        $found = $this->repository->findByAlias($alias);
        $this->assertNull($found);

        $withTrashed = $this->repository->findWithTrashed($model->getId());
        $this->assertNotNull($withTrashed);
        $this->assertNotNull($withTrashed->deleted_at);
    }

    // ==================== TESTS FILTERS ====================

    public function test_apply_filters_with_alias(): void
    {
        $this->createAndSaveTask('filter-alias-1', RecurringTaskStatus::WAITING);
        $this->createAndSaveTask('filter-alias-2', RecurringTaskStatus::WAITING);

        $filters = new RecurringTaskFiltersRecord(
            alias: new TaskSignatureVO('filter-alias-1')
        );

        $results = $this->repository->findBy(
            new FindByRecord(filters: $filters)
        );

        $this->assertCount(1, $results);
        $this->assertEquals('filter-alias-1', $results->first()->getAlias()->getValue());
    }

    public function test_apply_filters_with_status(): void
    {
        $this->createAndSaveTask('status-waiting', RecurringTaskStatus::WAITING);
        $this->createAndSaveTask('status-playing', RecurringTaskStatus::PLAYING);

        $filters = new RecurringTaskFiltersRecord(
            status: RecurringTaskStatus::WAITING
        );

        $results = $this->repository->findBy(
            new FindByRecord(filters: $filters)
        );

        $this->assertCount(1, $results);
        $this->assertEquals(RecurringTaskStatus::WAITING, $results->first()->getStatus());
    }

    public function test_apply_filters_with_canceled_status(): void
    {
        $this->createCancelledTask('canceled-1');
        $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING);

        $filters = new RecurringTaskFiltersRecord(
            status: RecurringTaskStatus::CANCELED
        );

        $results = $this->repository->findBy(
            new FindByRecord(filters: $filters)
        );

        $this->assertCount(1, $results);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $results->first()->getStatus());
    }

    public function test_apply_filters_with_cancelled_at(): void
    {
        $now = Carbon::now();

        $this->createCancelledTask('cancelled-1');
        $this->createCancelledTask('cancelled-2');
        $this->createAndSaveTask('not-cancelled', RecurringTaskStatus::WAITING);

        $from = $now->copy()->subHour()->format('Y-m-d\TH:i:sP');
        $to = $now->copy()->addHour()->format('Y-m-d\TH:i:sP');

        $filters = new RecurringTaskFiltersRecord(
            cancelled_at_from: new Iso8601DateTimeVO($from),
            cancelled_at_to: new Iso8601DateTimeVO($to)
        );

        $results = $this->repository->findBy(
            new FindByRecord(filters: $filters)
        );

        $this->assertCount(2, $results);
        $aliases = $results->map(fn ($task) => $task->getAlias()->getValue())->toArray();
        $this->assertContains('cancelled-1', $aliases);
        $this->assertContains('cancelled-2', $aliases);
        $this->assertNotContains('not-cancelled', $aliases);
    }
}
