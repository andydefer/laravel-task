<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Repositories;

use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\UniqueTaskFiltersRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

final class UniqueTaskRepositoryTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private UniqueTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new UniqueTaskRepository($this->debugRepository);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function createAndSaveTask(
        string $alias,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3
    ): UniqueTaskRecord {
        $scheduledAt = $scheduledAt ?? now();
        $id = $id ?? (string) Uuid::uuid4();

        $task = UniqueTaskRecord::from([
            'id' => $id,
            'alias' => $alias,
            'fqcn' => TestUniqueTask::class,
            'payload' => ['test' => 'unique'],
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:sP'),
            'grace_period_seconds' => $gracePeriodSeconds,
            'status' => $status,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
        ]);

        $this->repository->create($task);

        return $task;
    }

    private function createCanceledTask(string $alias): UniqueTaskRecord
    {
        $id = (string) Uuid::uuid4();
        $task = $this->createAndSaveTask($alias, $id, UniqueTaskStatus::CANCELED);

        return $task;
    }

    // ==================== TESTS FINDERS ====================

    public function test_find_pending_returns_collection(): void
    {
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask('pending-2', null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask('completed-1', null, UniqueTaskStatus::COMPLETED);

        $pending = $this->repository->findPending();

        $this->assertInstanceOf(Collection::class, $pending);
        $this->assertCount(2, $pending);

        foreach ($pending as $task) {
            $this->assertInstanceOf(UniqueTask::class, $task);
            $this->assertEquals(UniqueTaskStatus::PENDING, $task->getStatus());
        }
    }

    public function test_find_pending_with_limit(): void
    {
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask('pending-2', null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask('pending-3', null, UniqueTaskStatus::PENDING);

        $pending = $this->repository->findPending(2);

        $this->assertInstanceOf(Collection::class, $pending);
        $this->assertCount(2, $pending);
    }

    public function test_find_pending_returns_empty_collection_when_none(): void
    {
        $pending = $this->repository->findPending();
        $this->assertInstanceOf(Collection::class, $pending);
        $this->assertCount(0, $pending);
    }

    public function test_find_completed_returns_collection(): void
    {
        $this->createAndSaveTask('completed-1', null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask('completed-2', null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);

        $completed = $this->repository->findCompleted();

        $this->assertInstanceOf(Collection::class, $completed);
        $this->assertCount(2, $completed);

        foreach ($completed as $task) {
            $this->assertInstanceOf(UniqueTask::class, $task);
            $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
        }
    }

    public function test_find_completed_with_limit(): void
    {
        $this->createAndSaveTask('completed-1', null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask('completed-2', null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask('completed-3', null, UniqueTaskStatus::COMPLETED);

        $completed = $this->repository->findCompleted(2);

        $this->assertInstanceOf(Collection::class, $completed);
        $this->assertCount(2, $completed);
    }

    public function test_find_completed_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);

        $completed = $this->repository->findCompleted();
        $this->assertInstanceOf(Collection::class, $completed);
        $this->assertCount(0, $completed);
    }

    public function test_find_failed_returns_collection(): void
    {
        $this->createAndSaveTask('failed-1', null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask('failed-2', null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);

        $failed = $this->repository->findFailed();

        $this->assertInstanceOf(Collection::class, $failed);
        $this->assertCount(2, $failed);

        foreach ($failed as $task) {
            $this->assertInstanceOf(UniqueTask::class, $task);
            $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
        }
    }

    public function test_find_failed_with_limit(): void
    {
        $this->createAndSaveTask('failed-1', null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask('failed-2', null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask('failed-3', null, UniqueTaskStatus::FAILED);

        $failed = $this->repository->findFailed(2);

        $this->assertInstanceOf(Collection::class, $failed);
        $this->assertCount(2, $failed);
    }

    public function test_find_failed_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);

        $failed = $this->repository->findFailed();
        $this->assertInstanceOf(Collection::class, $failed);
        $this->assertCount(0, $failed);
    }

    // ==================== TESTS FIND CANCELED ====================

    public function test_find_canceled_returns_collection(): void
    {
        $this->createCanceledTask('canceled-1');
        $this->createCanceledTask('canceled-2');
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask('completed-1', null, UniqueTaskStatus::COMPLETED);

        $canceled = $this->repository->findCanceled();

        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(2, $canceled);

        foreach ($canceled as $task) {
            $this->assertInstanceOf(UniqueTask::class, $task);
            $this->assertEquals(UniqueTaskStatus::CANCELED, $task->getStatus());
        }
    }

    public function test_find_canceled_with_limit(): void
    {
        $this->createCanceledTask('canceled-1');
        $this->createCanceledTask('canceled-2');
        $this->createCanceledTask('canceled-3');

        $canceled = $this->repository->findCanceled(2);

        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(2, $canceled);
    }

    public function test_find_canceled_returns_empty_collection_when_none(): void
    {
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);

        $canceled = $this->repository->findCanceled();
        $this->assertInstanceOf(Collection::class, $canceled);
        $this->assertCount(0, $canceled);
    }

    // ==================== TESTS READY TO RUN ====================

    public function test_find_ready_to_run_returns_collection(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask('ready-1', null, UniqueTaskStatus::PENDING, $now->copy()->subHours(2));
        $this->createAndSaveTask('ready-2', null, UniqueTaskStatus::PENDING, $now->copy());
        $this->createAndSaveTask('not-ready-1', null, UniqueTaskStatus::PENDING, $now->copy()->addHours(2));
        $this->createAndSaveTask('completed-1', null, UniqueTaskStatus::COMPLETED, $now->copy()->subHours(2));

        $ready = $this->repository->findReadyToRun($now->format('Y-m-d\TH:i:sP'));

        $this->assertInstanceOf(Collection::class, $ready);
        $this->assertCount(2, $ready);

        $aliases = $ready->map(fn ($task) => $task->getAlias()->getValue())->toArray();
        $this->assertContains('ready-1', $aliases);
        $this->assertContains('ready-2', $aliases);
        $this->assertNotContains('not-ready-1', $aliases);
        $this->assertNotContains('completed-1', $aliases);
    }

    public function test_find_ready_to_run_with_limit(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask('ready-1', null, UniqueTaskStatus::PENDING, $now->copy()->subHours(2));
        $this->createAndSaveTask('ready-2', null, UniqueTaskStatus::PENDING, $now->copy()->subHours(1));
        $this->createAndSaveTask('ready-3', null, UniqueTaskStatus::PENDING, $now->copy());

        $ready = $this->repository->findReadyToRun($now->format('Y-m-d\TH:i:sP'), 2);

        $this->assertInstanceOf(Collection::class, $ready);
        $this->assertCount(2, $ready);
    }

    public function test_find_ready_to_run_returns_empty_collection_when_none(): void
    {
        $now = Carbon::now();
        $this->createAndSaveTask('future-1', null, UniqueTaskStatus::PENDING, $now->copy()->addHours(2));

        $ready = $this->repository->findReadyToRun($now->format('Y-m-d\TH:i:sP'));
        $this->assertInstanceOf(Collection::class, $ready);
        $this->assertCount(0, $ready);
    }

    public function test_find_expired_returns_collection(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask('expired-1', null, UniqueTaskStatus::PENDING, $now->copy()->subDays(2), 86400);
        $this->createAndSaveTask('not-expired-1', null, UniqueTaskStatus::PENDING, $now->copy()->subHours(12), 86400);

        $expired = $this->repository->findExpired($now->format('Y-m-d\TH:i:sP'));

        $this->assertInstanceOf(Collection::class, $expired);
        $this->assertCount(1, $expired);
        $this->assertEquals('expired-1', $expired->first()->getAlias()->getValue());
    }

    public function test_find_expired_with_limit(): void
    {
        $now = Carbon::now();

        $this->createAndSaveTask('expired-1', null, UniqueTaskStatus::PENDING, $now->copy()->subDays(2), 86400);
        $this->createAndSaveTask('expired-2', null, UniqueTaskStatus::PENDING, $now->copy()->subDays(1), 86400);
        $this->createAndSaveTask('not-expired-1', null, UniqueTaskStatus::PENDING, $now->copy()->subHours(12), 86400);

        $expired = $this->repository->findExpired($now->format('Y-m-d\TH:i:sP'), 1);

        $this->assertInstanceOf(Collection::class, $expired);
        $this->assertCount(1, $expired);
    }

    public function test_find_expired_returns_empty_collection_when_none(): void
    {
        $now = Carbon::now();
        $this->createAndSaveTask('not-expired-1', null, UniqueTaskStatus::PENDING, $now->copy()->subHours(12), 86400);

        $expired = $this->repository->findExpired($now->format('Y-m-d\TH:i:sP'));
        $this->assertInstanceOf(Collection::class, $expired);
        $this->assertCount(0, $expired);
    }

    // ==================== TESTS findById ====================

    public function test_find_by_id_returns_model(): void
    {
        $id = (string) Uuid::uuid4();
        $this->createAndSaveTask('test-find-id', $id, UniqueTaskStatus::PENDING);

        $found = $this->repository->findById($id);

        $this->assertNotNull($found);
        $this->assertInstanceOf(UniqueTask::class, $found);
        $this->assertEquals($id, $found->getId()->getValue());
        $this->assertEquals('test-find-id', $found->getAlias()->getValue());
        $this->assertEquals(UniqueTaskStatus::PENDING, $found->getStatus());
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $found = $this->repository->findById('00000000-0000-0000-0000-000000000000');
        $this->assertNull($found);
    }

    public function test_find_by_id_returns_null_when_invalid_format(): void
    {
        $found = $this->repository->findById('invalid-uuid-format');
        $this->assertNull($found);
    }

    // ==================== TESTS MOVES ====================

    public function test_move_to_completed_updates_status(): void
    {
        $id = (string) Uuid::uuid4();
        $task = $this->createAndSaveTask('test-move-completed', $id, UniqueTaskStatus::PENDING);

        $this->repository->moveToCompleted($task);

        $found = $this->repository->findById($id);
        $this->assertNotNull($found);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
    }

    public function test_move_to_completed_throws_exception_when_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $task = UniqueTaskRecord::from([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => now()->toIso8601String(),
        ]);

        $this->repository->moveToCompleted($task);
    }

    public function test_move_to_failed_updates_status(): void
    {
        $id = (string) Uuid::uuid4();
        $task = $this->createAndSaveTask('test-move-failed', $id, UniqueTaskStatus::PENDING);

        $this->repository->moveToFailed($task);

        $found = $this->repository->findById($id);
        $this->assertNotNull($found);
        $this->assertEquals(UniqueTaskStatus::FAILED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
    }

    public function test_move_to_failed_throws_exception_when_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $task = UniqueTaskRecord::from([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => now()->toIso8601String(),
        ]);

        $this->repository->moveToFailed($task);
    }

    // ==================== TEST MOVE TO CANCELED ====================

    public function test_move_to_canceled_updates_status(): void
    {
        $id = (string) Uuid::uuid4();
        $task = $this->createAndSaveTask('test-move-canceled', $id, UniqueTaskStatus::PENDING);

        $this->repository->moveToCanceled($task);

        $found = $this->repository->findById($id);
        $this->assertNotNull($found);
        $this->assertEquals(UniqueTaskStatus::CANCELED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
    }

    public function test_move_to_canceled_throws_exception_when_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $task = UniqueTaskRecord::from([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => now()->toIso8601String(),
        ]);

        $this->repository->moveToCanceled($task);
    }

    public function test_update_attempts_updates_attempts(): void
    {
        $id = (string) Uuid::uuid4();
        $task = $this->createAndSaveTask('test-update-attempts', $id, UniqueTaskStatus::PENDING);

        $this->repository->updateAttempts($task, 2);

        $found = $this->repository->findById($id);
        $this->assertNotNull($found);
        $this->assertEquals(2, $found->getAttempts()->value);
    }

    public function test_update_attempts_throws_exception_when_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $task = UniqueTaskRecord::from([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => now()->toIso8601String(),
        ]);

        $this->repository->updateAttempts($task, 2);
    }

    public function test_add_debug_creates_debug_entry(): void
    {
        $id = (string) Uuid::uuid4();
        $task = $this->createAndSaveTask('test-add-debug', $id, UniqueTaskStatus::PENDING);

        $this->repository->addDebug($task, 'succeeded', 'Task executed successfully');

        $debugs = $this->debugRepository->findByTask('unique', $id);
        $this->assertCount(1, $debugs);

        $debugData = $debugs->first()->getData();
        $this->assertEquals('succeeded', $debugData->status);
        $this->assertEquals('Task executed successfully', $debugData->info);
        $this->assertNotNull($debugData->acted_at);
    }

    // ==================== TESTS COUNTS ====================

    public function test_count_pending(): void
    {
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask('pending-2', null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask('completed-1', null, UniqueTaskStatus::COMPLETED);

        $this->assertEquals(2, $this->repository->countPending());
    }

    public function test_count_pending_returns_zero_when_none(): void
    {
        $this->createAndSaveTask('completed-1', null, UniqueTaskStatus::COMPLETED);
        $this->assertEquals(0, $this->repository->countPending());
    }

    public function test_count_completed(): void
    {
        $this->createAndSaveTask('completed-1', null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask('completed-2', null, UniqueTaskStatus::COMPLETED);
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);

        $this->assertEquals(2, $this->repository->countCompleted());
    }

    public function test_count_completed_returns_zero_when_none(): void
    {
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);
        $this->assertEquals(0, $this->repository->countCompleted());
    }

    public function test_count_failed(): void
    {
        $this->createAndSaveTask('failed-1', null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask('failed-2', null, UniqueTaskStatus::FAILED);
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);

        $this->assertEquals(2, $this->repository->countFailed());
    }

    public function test_count_failed_returns_zero_when_none(): void
    {
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);
        $this->assertEquals(0, $this->repository->countFailed());
    }

    public function test_count_canceled(): void
    {
        $this->createCanceledTask('canceled-1');
        $this->createCanceledTask('canceled-2');
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);

        $this->assertEquals(2, $this->repository->countCanceled());
    }

    public function test_count_canceled_returns_zero_when_none(): void
    {
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);
        $this->assertEquals(0, $this->repository->countCanceled());
    }

    // ==================== TESTS CREATE ====================

    public function test_create_persists_task(): void
    {
        $id = (string) Uuid::uuid4();
        $scheduledAt = now()->addDays(1);

        $task = UniqueTaskRecord::from([
            'id' => $id,
            'alias' => 'create-test',
            'fqcn' => TestUniqueTask::class,
            'payload' => ['test' => 'create'],
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:sP'),
            'grace_period_seconds' => 43200,
            'status' => UniqueTaskStatus::PENDING,
            'attempts' => 0,
            'max_attempts' => 5,
        ]);

        $this->repository->create($task);

        $found = $this->repository->findById($id);
        $this->assertNotNull($found);
        $this->assertEquals('create-test', $found->getAlias()->getValue());
        $this->assertEquals(43200, $found->getGracePeriodSeconds());
        $this->assertEquals(5, $found->getMaxAttempts()->value);
        $this->assertEquals(UniqueTaskStatus::PENDING, $found->getStatus());
    }

    // ==================== TESTS UPDATE ====================

    public function test_update_updates_task(): void
    {
        $id = (string) Uuid::uuid4();
        $task = $this->createAndSaveTask('update-test', $id, UniqueTaskStatus::PENDING);
        $model = $this->repository->findById($id);
        $this->assertNotNull($model);

        $this->repository->updateRaw(
            $model->getId()->getValue(),
            [
                'alias' => 'updated-alias',
                'fqcn' => TestRecurringTask::class,
                'payload' => json_encode(['updated' => true]),
                'grace_period_seconds' => 172800,
                'status' => UniqueTaskStatus::COMPLETED->value,
                'attempts' => 2,
                'max_attempts' => 5,
                'finished_at' => now()->toDateTimeString(),
            ]
        );

        $found = $this->repository->findById($id);
        $this->assertNotNull($found);
        $this->assertEquals('updated-alias', $found->getAlias()->getValue());
        $this->assertEquals(172800, $found->getGracePeriodSeconds());
        $this->assertEquals(2, $found->getAttempts()->value);
        $this->assertEquals(5, $found->getMaxAttempts()->value);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $found->getStatus());
        $this->assertNotNull($found->getFinishedAt());
    }

    // ==================== TESTS DELETE ====================

    public function test_delete_soft_deletes_task(): void
    {
        $id = (string) Uuid::uuid4();
        $this->createAndSaveTask('delete-test', $id, UniqueTaskStatus::PENDING);

        $model = $this->repository->findById($id);
        $this->assertNotNull($model);
        $model->delete();

        $found = $this->repository->findById($id);
        $this->assertNull($found);

        $withTrashed = UniqueTask::withTrashed()->where('id', $id)->first();
        $this->assertNotNull($withTrashed);
        $this->assertNotNull($withTrashed->deleted_at);
    }

    // ==================== TESTS FILTERS ====================

    public function test_apply_filters_with_alias(): void
    {
        $this->createAndSaveTask('filter-alias-1', null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask('filter-alias-2', null, UniqueTaskStatus::PENDING);

        $filters = UniqueTaskFiltersRecord::from([
            'alias' => 'filter-alias-1',
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals('filter-alias-1', $results->first()->getAlias()->getValue());
    }

    public function test_apply_filters_with_status(): void
    {
        $this->createAndSaveTask('status-pending', null, UniqueTaskStatus::PENDING);
        $this->createAndSaveTask('status-completed', null, UniqueTaskStatus::COMPLETED);

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
        $this->createCanceledTask('canceled-1');
        $this->createAndSaveTask('pending-1', null, UniqueTaskStatus::PENDING);

        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::CANCELED,
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals(UniqueTaskStatus::CANCELED, $results->first()->getStatus());
    }
}
