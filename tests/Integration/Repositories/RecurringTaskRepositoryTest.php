<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Repositories;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\FreshStateResultRecord;
use AndyDefer\Task\Records\RecurringTaskFiltersRecord;
use AndyDefer\Task\Records\RecurringTaskReadyToRunResultRecord;
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

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new RecurringTaskRepository($this->debugRepository);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Carbon::setTestNow();
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
        $startAt = $startAt ?? now()->addHours(2);
        $endAt = $endAt ?? now()->addDays(7);

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
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('test-find-by-alias', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $found = $this->repository->findByAlias('test-find-by-alias');

            $this->assertNotNull($found);
            $this->assertInstanceOf(RecurringTask::class, $found);
            $this->assertEquals('test-find-by-alias', $found->getAlias()->getValue());
            $this->assertEquals(RecurringTaskStatus::WAITING, $found->getStatus());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_by_alias_returns_null_when_not_found(): void
    {
        $found = $this->repository->findByAlias('non-existent');
        $this->assertNull($found);
    }

    public function test_find_by_alias_returns_null_when_deleted(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('delete-test', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
            $model = $this->repository->findByAlias('delete-test');
            $this->repository->delete($model->getId());

            $found = $this->repository->findByAlias('delete-test');
            $this->assertNull($found);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================== TESTS FINDERS ====================

    public function test_find_waiting_returns_collection(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
            $this->createAndSaveTask('waiting-2', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(3));
            $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING, $frozenNow->copy()->addHours(2));

            $waiting = $this->repository->findWaiting();

            $this->assertInstanceOf(Collection::class, $waiting);
            $this->assertCount(2, $waiting);

            foreach ($waiting as $task) {
                $this->assertInstanceOf(RecurringTask::class, $task);
                $this->assertEquals(RecurringTaskStatus::WAITING, $task->getStatus());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_waiting_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
            $this->createAndSaveTask('waiting-2', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(3));
            $this->createAndSaveTask('waiting-3', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(4));

            $waiting = $this->repository->findWaiting(2);

            $this->assertInstanceOf(Collection::class, $waiting);
            $this->assertCount(2, $waiting);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_waiting_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING, $frozenNow->copy()->addHours(2));

            $waiting = $this->repository->findWaiting();
            $this->assertInstanceOf(Collection::class, $waiting);
            $this->assertCount(0, $waiting);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_playing_returns_collection(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING);
            $this->createAndSaveTask('playing-2', RecurringTaskStatus::PLAYING);
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $playing = $this->repository->findPlaying();

            $this->assertInstanceOf(Collection::class, $playing);
            $this->assertCount(2, $playing);

            foreach ($playing as $task) {
                $this->assertInstanceOf(RecurringTask::class, $task);
                $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_playing_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING);
            $this->createAndSaveTask('playing-2', RecurringTaskStatus::PLAYING);
            $this->createAndSaveTask('playing-3', RecurringTaskStatus::PLAYING);

            $playing = $this->repository->findPlaying(2);

            $this->assertInstanceOf(Collection::class, $playing);
            $this->assertCount(2, $playing);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_playing_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $playing = $this->repository->findPlaying();
            $this->assertInstanceOf(Collection::class, $playing);
            $this->assertCount(0, $playing);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_paused_returns_collection(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('paused-1', RecurringTaskStatus::PAUSED);
            $this->createAndSaveTask('paused-2', RecurringTaskStatus::PAUSED);
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $paused = $this->repository->findPaused();

            $this->assertInstanceOf(Collection::class, $paused);
            $this->assertCount(2, $paused);

            foreach ($paused as $task) {
                $this->assertInstanceOf(RecurringTask::class, $task);
                $this->assertEquals(RecurringTaskStatus::PAUSED, $task->getStatus());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_paused_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('paused-1', RecurringTaskStatus::PAUSED);
            $this->createAndSaveTask('paused-2', RecurringTaskStatus::PAUSED);
            $this->createAndSaveTask('paused-3', RecurringTaskStatus::PAUSED);

            $paused = $this->repository->findPaused(2);

            $this->assertInstanceOf(Collection::class, $paused);
            $this->assertCount(2, $paused);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_paused_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $paused = $this->repository->findPaused();
            $this->assertInstanceOf(Collection::class, $paused);
            $this->assertCount(0, $paused);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_finished_returns_collection(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('finished-1', RecurringTaskStatus::FINISHED);
            $this->createAndSaveTask('finished-2', RecurringTaskStatus::FINISHED);
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $finished = $this->repository->findFinished();

            $this->assertInstanceOf(Collection::class, $finished);
            $this->assertCount(2, $finished);

            foreach ($finished as $task) {
                $this->assertInstanceOf(RecurringTask::class, $task);
                $this->assertEquals(RecurringTaskStatus::FINISHED, $task->getStatus());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_finished_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('finished-1', RecurringTaskStatus::FINISHED);
            $this->createAndSaveTask('finished-2', RecurringTaskStatus::FINISHED);
            $this->createAndSaveTask('finished-3', RecurringTaskStatus::FINISHED);

            $finished = $this->repository->findFinished(2);

            $this->assertInstanceOf(Collection::class, $finished);
            $this->assertCount(2, $finished);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_finished_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $finished = $this->repository->findFinished();
            $this->assertInstanceOf(Collection::class, $finished);
            $this->assertCount(0, $finished);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================== TESTS FIND CANCELED ====================

    public function test_find_canceled_returns_collection(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createCancelledTask('canceled-1');
            $this->createCancelledTask('canceled-2');
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
            $this->createAndSaveTask('finished-1', RecurringTaskStatus::FINISHED);

            $canceled = $this->repository->findCanceled();

            $this->assertInstanceOf(Collection::class, $canceled);
            $this->assertCount(2, $canceled);

            foreach ($canceled as $task) {
                $this->assertInstanceOf(RecurringTask::class, $task);
                $this->assertEquals(RecurringTaskStatus::CANCELED, $task->getStatus());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_canceled_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createCancelledTask('canceled-1');
            $this->createCancelledTask('canceled-2');
            $this->createCancelledTask('canceled-3');

            $canceled = $this->repository->findCanceled(2);

            $this->assertInstanceOf(Collection::class, $canceled);
            $this->assertCount(2, $canceled);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_canceled_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $canceled = $this->repository->findCanceled();
            $this->assertInstanceOf(Collection::class, $canceled);
            $this->assertCount(0, $canceled);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================== TESTS READY TO RUN ====================

    public function test_find_ready_to_run_returns_result_record(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('ready-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->subHours(2));
            $this->createAndSaveTask('ready-2', RecurringTaskStatus::WAITING, $frozenNow->copy());
            $this->createAndSaveTask('not-ready-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
            $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING, $frozenNow->copy()->subHours(2));

            $result = $this->repository->findReadyToRun($this->formatDate($frozenNow));

            // ✅ Vérifier que le résultat contient les tâches et les statistiques
            $this->assertInstanceOf(RecurringTaskReadyToRunResultRecord::class, $result);
            $this->assertInstanceOf(RecurringTaskRecordCollection::class, $result->tasks);
            $this->assertInstanceOf(FreshStateResultRecord::class, $result->fresh_state);

            // ✅ Vérifier les tâches prêtes
            $this->assertCount(3, $result->tasks);

            // ✅ Vérifier les statistiques de freshState
            $this->assertEquals(2, $result->fresh_state->waiting_to_playing->value); // ready-1, ready-2
            $this->assertEquals(0, $result->fresh_state->playing_to_finished->value);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_ready_to_run_with_limit(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            for ($i = 1; $i <= 5; $i++) {
                $this->createAndSaveTask(
                    "ready-{$i}",
                    RecurringTaskStatus::WAITING,
                    $frozenNow->copy()->subHours(2)
                );
            }

            $result = $this->repository->findReadyToRun($this->formatDate($frozenNow), 3);

            $this->assertCount(3, $result->tasks);
            $this->assertEquals(5, $result->fresh_state->waiting_to_playing->value);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_ready_to_run_returns_empty_collection_when_none(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('not-ready-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $result = $this->repository->findReadyToRun($this->formatDate($frozenNow));

            $this->assertInstanceOf(RecurringTaskRecordCollection::class, $result->tasks);
            $this->assertCount(0, $result->tasks);
            $this->assertEquals(0, $result->fresh_state->waiting_to_playing->value);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_ready_to_run_counts_finished_tasks(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            // ✅ Tâche qui va expirer
            $this->createAndSaveTask(
                'expired-task',
                RecurringTaskStatus::PLAYING,
                $frozenNow->copy()->subDays(7),
                $frozenNow->copy()->subHours(1)
            );

            // ✅ Tâche qui va démarrer
            $this->createAndSaveTask(
                'start-task',
                RecurringTaskStatus::WAITING,
                $frozenNow->copy()->subHours(2)
            );

            $result = $this->repository->findReadyToRun($this->formatDate($frozenNow));

            // ✅ Une tâche expirée → playing_to_finished = 1
            $this->assertEquals(1, $result->fresh_state->playing_to_finished->value);

            // ✅ Une tâche qui démarre → waiting_to_playing = 1
            $this->assertEquals(1, $result->fresh_state->waiting_to_playing->value);

            // ✅ Une tâche en PLAYING (la tâche qui a démarré)
            $this->assertCount(1, $result->tasks);
            $this->assertEquals('start-task', $result->tasks->first()->alias->value);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================== TESTS CANCELLED COUNTS ====================

    public function test_count_cancelled_returns_zero_when_no_cancelled_tasks(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('cancelled-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
            $this->createAndSaveTask('cancelled-2', RecurringTaskStatus::PLAYING);

            $this->assertEquals(0, $this->repository->countCanceled());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_count_cancelled_returns_count_of_cancelled_tasks(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createCancelledTask('cancelled-1');
            $this->createCancelledTask('cancelled-2');
            $this->createAndSaveTask('not-cancelled-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $this->assertEquals(2, $this->repository->countCanceled());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_find_finished_excludes_cancelled_tasks(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createCancelledTask('cancelled-1');
            $this->createAndSaveTask('finished-1', RecurringTaskStatus::FINISHED);

            $finished = $this->repository->findFinished();

            $this->assertCount(1, $finished);

            $aliases = $finished->map(fn ($task) => $task->getAlias()->getValue())->toArray();
            $this->assertContains('finished-1', $aliases);
            $this->assertNotContains('cancelled-1', $aliases);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================== TESTS MOVES ====================

    public function test_move_to_playing_updates_status(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $task = $this->createAndSaveTask('test-move-playing', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $this->repository->moveToPlaying($task);

            $found = $this->repository->findByAlias('test-move-playing');
            $this->assertNotNull($found);
            $this->assertEquals(RecurringTaskStatus::PLAYING, $found->getStatus());
        } finally {
            Carbon::setTestNow();
        }
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
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $task = $this->createAndSaveTask('test-move-paused', RecurringTaskStatus::PLAYING);

            $this->repository->moveToPaused($task);

            $found = $this->repository->findByAlias('test-move-paused');
            $this->assertNotNull($found);
            $this->assertEquals(RecurringTaskStatus::PAUSED, $found->getStatus());
        } finally {
            Carbon::setTestNow();
        }
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
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $task = $this->createAndSaveTask('test-move-waiting', RecurringTaskStatus::PAUSED);

            $this->repository->moveToWaiting($task);

            $found = $this->repository->findByAlias('test-move-waiting');
            $this->assertNotNull($found);
            $this->assertEquals(RecurringTaskStatus::WAITING, $found->getStatus());
        } finally {
            Carbon::setTestNow();
        }
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
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $task = $this->createAndSaveTask('test-move-finished', RecurringTaskStatus::PLAYING);

            $this->repository->moveToFinished($task);

            $found = $this->repository->findByAlias('test-move-finished');
            $this->assertNotNull($found);
            $this->assertEquals(RecurringTaskStatus::FINISHED, $found->getStatus());
            $this->assertNotNull($found->getFinishedAt());
        } finally {
            Carbon::setTestNow();
        }
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
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $task = $this->createAndSaveTask('test-move-canceled', RecurringTaskStatus::PLAYING);

            $this->repository->moveToCanceled($task);

            $found = $this->repository->findByAlias('test-move-canceled');
            $this->assertNotNull($found);
            $this->assertEquals(RecurringTaskStatus::CANCELED, $found->getStatus());
            $this->assertNotNull($found->getFinishedAt());
            $this->assertNotNull($found->getCancelledAt());
        } finally {
            Carbon::setTestNow();
        }
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
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
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
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_update_after_run_failure_updates_last_run_at_and_adds_debug_with_error(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
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
        } finally {
            Carbon::setTestNow();
        }
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
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
            $this->createAndSaveTask('waiting-2', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(3));
            $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING);

            $this->assertEquals(2, $this->repository->countWaiting());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_count_playing(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('playing-1', RecurringTaskStatus::PLAYING);
            $this->createAndSaveTask('playing-2', RecurringTaskStatus::PLAYING);
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $this->assertEquals(2, $this->repository->countPlaying());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_count_paused(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('paused-1', RecurringTaskStatus::PAUSED);
            $this->createAndSaveTask('paused-2', RecurringTaskStatus::PAUSED);
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $this->assertEquals(2, $this->repository->countPaused());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_count_finished(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('finished-1', RecurringTaskStatus::FINISHED);
            $this->createAndSaveTask('finished-2', RecurringTaskStatus::FINISHED);
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $this->assertEquals(2, $this->repository->countFinished());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_count_canceled(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createCancelledTask('canceled-1');
            $this->createCancelledTask('canceled-2');
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $this->assertEquals(2, $this->repository->countCanceled());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================== TESTS CREATE ====================

    public function test_create_persists_task(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $alias = 'create-test';
            $startAt = $frozenNow->copy()->addDays(1);
            $endAt = $frozenNow->copy()->addDays(8);

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
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================== TESTS UPDATE ====================

    public function test_update_updates_task(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $alias = 'update-test';
            $task = $this->createAndSaveTask($alias, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
            $model = $this->repository->findByAlias($alias);

            $updated = new RecurringTaskRecord(
                alias: new TaskSignatureVO($alias),
                fqcn: 'UpdatedTask',
                payload: StrictDataObject::from(['updated' => true]),
                interval_seconds: new CounterVO(14400),
                start_at: new Iso8601DateTimeVO($this->formatDate($frozenNow->copy()->addDays(2))),
                end_at: new Iso8601DateTimeVO($this->formatDate($frozenNow->copy()->addDays(9))),
                status: RecurringTaskStatus::PLAYING,
                last_run_at: new Iso8601DateTimeVO($this->formatDate($frozenNow)),
            );

            $this->repository->update($model->getId(), $updated);

            $found = $this->repository->findByAlias($alias);
            $this->assertNotNull($found);
            $this->assertEquals('UpdatedTask', $found->getFqcn());
            $this->assertEquals(14400, $found->getIntervalSeconds()->value);
            $this->assertEquals(RecurringTaskStatus::PLAYING, $found->getStatus());
            $this->assertNotNull($found->getLastRunAt());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================== TESTS DELETE ====================

    public function test_delete_soft_deletes_task(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $alias = 'delete-test';
            $this->createAndSaveTask($alias, RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $model = $this->repository->findByAlias($alias);
            $this->repository->delete($model->getId());

            $found = $this->repository->findByAlias($alias);
            $this->assertNull($found);

            $withTrashed = $this->repository->findWithTrashed($model->getId());
            $this->assertNotNull($withTrashed);
            $this->assertNotNull($withTrashed->deleted_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================== TESTS FILTERS ====================

    public function test_apply_filters_with_alias(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('filter-alias-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
            $this->createAndSaveTask('filter-alias-2', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(3));

            $filters = new RecurringTaskFiltersRecord(
                alias: new TaskSignatureVO('filter-alias-1')
            );

            $results = $this->repository->findBy(
                new FindByRecord(filters: $filters)
            );

            $this->assertCount(1, $results);
            $this->assertEquals('filter-alias-1', $results->first()->getAlias()->getValue());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_apply_filters_with_status(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask('status-waiting', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));
            $this->createAndSaveTask('status-playing', RecurringTaskStatus::PLAYING);

            $filters = new RecurringTaskFiltersRecord(
                status: RecurringTaskStatus::WAITING
            );

            $results = $this->repository->findBy(
                new FindByRecord(filters: $filters)
            );

            $this->assertCount(1, $results);
            $this->assertEquals(RecurringTaskStatus::WAITING, $results->first()->getStatus());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_apply_filters_with_canceled_status(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createCancelledTask('canceled-1');
            $this->createAndSaveTask('waiting-1', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $filters = new RecurringTaskFiltersRecord(
                status: RecurringTaskStatus::CANCELED
            );

            $results = $this->repository->findBy(
                new FindByRecord(filters: $filters)
            );

            $this->assertCount(1, $results);
            $this->assertEquals(RecurringTaskStatus::CANCELED, $results->first()->getStatus());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_apply_filters_with_cancelled_at(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createCancelledTask('cancelled-1');
            $this->createCancelledTask('cancelled-2');
            $this->createAndSaveTask('not-cancelled', RecurringTaskStatus::WAITING, $frozenNow->copy()->addHours(2));

            $from = $frozenNow->copy()->subHour();
            $to = $frozenNow->copy()->addHour();

            $filters = new RecurringTaskFiltersRecord(
                cancelled_at_from: new Iso8601DateTimeVO($this->formatDate($from)),
                cancelled_at_to: new Iso8601DateTimeVO($this->formatDate($to))
            );

            $results = $this->repository->findBy(
                new FindByRecord(filters: $filters)
            );

            $this->assertCount(2, $results);
            $aliases = $results->map(fn ($task) => $task->getAlias()->getValue())->toArray();
            $this->assertContains('cancelled-1', $aliases);
            $this->assertContains('cancelled-2', $aliases);
            $this->assertNotContains('not-cancelled', $aliases);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================== TESTS FRESH STATE ====================

    public function test_fresh_state_moves_waiting_to_playing_when_start_at_reached(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask(
                'fresh-test-1',
                RecurringTaskStatus::WAITING,
                $frozenNow->copy()->subHours(2)
            );

            $this->createAndSaveTask(
                'fresh-test-2',
                RecurringTaskStatus::WAITING,
                $frozenNow->copy()->addHours(2)
            );

            $result = $this->repository->findReadyToRun($this->formatDate($frozenNow));

            $this->assertEquals(1, $result->fresh_state->waiting_to_playing->value);
            $this->assertEquals('fresh-test-1', $result->tasks->first()->alias->value);
            $this->assertEquals(RecurringTaskStatus::PLAYING, $result->tasks->first()->status);

            $waiting = $this->repository->findWaiting();
            $this->assertCount(1, $waiting);
            $this->assertEquals('fresh-test-2', $waiting->first()->getAlias()->value);
            $this->assertEquals(RecurringTaskStatus::WAITING, $waiting->first()->getStatus());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_fresh_state_moves_playing_to_finished_when_end_at_reached(): void
    {
        $frozenNow = Carbon::create(2026, 6, 22, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        try {
            $this->createAndSaveTask(
                'expired-test-1',
                RecurringTaskStatus::PLAYING,
                $frozenNow->copy()->subDays(7),
                $frozenNow->copy()->subHours(1)
            );

            $this->createAndSaveTask(
                'expired-test-2',
                RecurringTaskStatus::PLAYING,
                $frozenNow->copy()->subDays(7),
                $frozenNow->copy()->addDays(7)
            );

            $result = $this->repository->findReadyToRun($this->formatDate($frozenNow));

            $this->assertEquals(1, $result->fresh_state->playing_to_finished->value);
            $this->assertCount(1, $result->tasks);
            $this->assertEquals('expired-test-2', $result->tasks->first()->alias->value);
            $this->assertEquals(RecurringTaskStatus::PLAYING, $result->tasks->first()->status);

            $finished = $this->repository->findFinished();
            $this->assertCount(1, $finished);
            $this->assertEquals('expired-test-1', $finished->first()->getAlias()->value);
            $this->assertEquals(RecurringTaskStatus::FINISHED, $finished->first()->getStatus());
            $this->assertNotNull($finished->first()->getFinishedAt());
        } finally {
            Carbon::setTestNow();
        }
    }
}
