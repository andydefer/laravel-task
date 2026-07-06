<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Repositories;

use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Models\TaskExecutionDebug;
use AndyDefer\Task\Records\TaskExecutionDebugFiltersRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

final class TaskExecutionDebugRepositoryTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private TaskExecutionDebugRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new TaskExecutionDebugRepository;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function generateUuid(): string
    {
        return Uuid::uuid4()->toString();
    }

    private function createAliasVO(string $uuid): TaskAliasVO
    {
        return new TaskAliasVO('unique@'.$uuid);
    }

    private function createFqcnVO(string $fqcn): TaskFqcnVO
    {
        return new TaskFqcnVO($fqcn);
    }

    // ==================== TESTS ====================

    public function test_add_debug_creates_record(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Task executed successfully')
        );

        $this->assertDatabaseHas('task_execution_debugs', [
            'alias' => 'unique@'.$uuid,
            'fqcn' => TestUniqueTask::class,
            'status' => ExecutionStatus::SUCCEEDED->value,
        ]);

        $record = TaskExecutionDebug::first();
        $this->assertNotNull($record);

        // ✅ status est sur le modèle, pas dans data !
        $this->assertEquals(ExecutionStatus::SUCCEEDED, $record->getStatus());
        $this->assertEquals('Task executed successfully', $record->getData()->info);
    }

    public function test_add_debug_with_error_and_duration(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::FAILED,
            info: new DescriptionVO('Task failed'),
            duration_ms: new MillisecondsVO(1500),
            error: new DescriptionVO('Connection timeout')
        );

        $record = TaskExecutionDebug::first();
        $this->assertNotNull($record);

        // ✅ status est sur le modèle, pas dans data !
        $this->assertEquals(ExecutionStatus::FAILED, $record->getStatus());
        $this->assertEquals('Task failed', $record->getData()->info);
        $this->assertEquals(1500, $record->getData()->duration_ms);
        $this->assertEquals('Connection timeout', $record->getData()->error);
    }

    public function test_add_debug_with_start_creates_record_with_null_ended_at(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebugWithStart(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Task started')
        );

        $record = TaskExecutionDebug::first();
        $this->assertNotNull($record);

        // ✅ status est sur le modèle
        $this->assertEquals(ExecutionStatus::SUCCEEDED, $record->getStatus());
        $this->assertEquals('Task started', $record->getData()->info);
        $this->assertNotNull($record->getStartedAt());
        $this->assertNull($record->getEndedAt());
    }

    public function test_update_debug_with_end_updates_record(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebugWithStart(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Task started')
        );

        $this->repository->updateDebugWithEnd(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            duration_ms: new MillisecondsVO(2500)
        );

        $record = TaskExecutionDebug::first();
        $this->assertNotNull($record);

        // ✅ status est sur le modèle
        $this->assertEquals(ExecutionStatus::SUCCEEDED, $record->getStatus());
        $this->assertEquals('Task executed successfully', $record->getData()->info);
        $this->assertEquals(2500, $record->getData()->duration_ms);
        $this->assertNotNull($record->getEndedAt());
    }

    public function test_update_debug_with_end_with_error(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebugWithStart(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Task started')
        );

        $this->repository->updateDebugWithEnd(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::FAILED,
            error: new DescriptionVO('Task execution failed'),
            duration_ms: new MillisecondsVO(1200)
        );

        $record = TaskExecutionDebug::first();
        $this->assertNotNull($record);

        // ✅ status est sur le modèle
        $this->assertEquals(ExecutionStatus::FAILED, $record->getStatus());
        $this->assertEquals('Task execution failed', $record->getData()->info);
        $this->assertEquals(1200, $record->getData()->duration_ms);
        $this->assertEquals('Task execution failed', $record->getData()->error);
        $this->assertNotNull($record->getEndedAt());
    }

    public function test_find_by_status_returns_collection(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Success task')
        );

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::FAILED,
            info: new DescriptionVO('Failed task')
        );

        $results = $this->repository->findByStatus(ExecutionStatus::SUCCEEDED);

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(1, $results);

        // ✅ status est sur le modèle
        $this->assertEquals(ExecutionStatus::SUCCEEDED, $results->first()->getStatus());
    }

    public function test_apply_filters_with_status(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Success task')
        );

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::FAILED,
            info: new DescriptionVO('Failed task')
        );

        $filters = TaskExecutionDebugFiltersRecord::from([
            'status' => ExecutionStatus::SUCCEEDED,
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);

        // ✅ status est sur le modèle
        $this->assertEquals(ExecutionStatus::SUCCEEDED, $results->first()->getStatus());
    }

    // ==================== AUTRES TESTS ====================

    public function test_update_debug_with_end_does_nothing_when_not_found(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->updateDebugWithEnd(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED
        );

        $this->assertDatabaseCount('task_execution_debugs', 0);
    }

    public function test_find_by_alias_returns_collection(): void
    {
        $uuid1 = $this->generateUuid();
        $uuid2 = $this->generateUuid();
        $alias1 = $this->createAliasVO($uuid1);
        $alias2 = $this->createAliasVO($uuid2);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias1,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('First execution')
        );

        $this->repository->addDebug(
            alias: $alias1,
            fqcn: $fqcn,
            status: ExecutionStatus::FAILED,
            info: new DescriptionVO('Second execution')
        );

        $this->repository->addDebug(
            alias: $alias2,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Other task')
        );

        $results = $this->repository->findByAlias($alias1);

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(2, $results);

        foreach ($results as $result) {
            $this->assertInstanceOf(TaskExecutionDebug::class, $result);
            $this->assertEquals('unique@'.$uuid1, $result->getAlias()->getValue());
        }
    }

    public function test_find_by_alias_returns_empty_collection_when_not_found(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $results = $this->repository->findByAlias($alias);

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(0, $results);
    }

    public function test_find_by_fqcn_returns_collection(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn1 = $this->createFqcnVO(TestUniqueTask::class);
        $fqcn2 = $this->createFqcnVO(TestRecurringTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn1,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Unique task')
        );

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn2,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Recurring task')
        );

        $results = $this->repository->findByFqcn($fqcn1);

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(1, $results);
        $this->assertEquals($fqcn1->getValue(), $results->first()->getFqcn()->getValue());
    }

    public function test_find_by_alias_and_fqcn_returns_collection(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('First execution')
        );

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::FAILED,
            info: new DescriptionVO('Second execution')
        );

        $results = $this->repository->findByAliasAndFqcn($alias, $fqcn);

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(2, $results);

        foreach ($results as $result) {
            $this->assertEquals('unique@'.$uuid, $result->getAlias()->getValue());
            $this->assertEquals($fqcn->getValue(), $result->getFqcn()->getValue());
        }
    }

    public function test_clear_by_alias_deletes_all_debug_entries(): void
    {
        $uuid1 = $this->generateUuid();
        $uuid2 = $this->generateUuid();
        $alias1 = $this->createAliasVO($uuid1);
        $alias2 = $this->createAliasVO($uuid2);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias1,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('First execution')
        );

        $this->repository->addDebug(
            alias: $alias1,
            fqcn: $fqcn,
            status: ExecutionStatus::FAILED,
            info: new DescriptionVO('Second execution')
        );

        $this->repository->addDebug(
            alias: $alias2,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Other task')
        );

        $this->repository->clearByAlias($alias1);

        $remaining = $this->repository->findByAlias($alias1);
        $this->assertCount(0, $remaining);

        $other = $this->repository->findByAlias($alias2);
        $this->assertCount(1, $other);
    }

    public function test_clear_by_alias_does_nothing_when_no_entries(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $this->repository->clearByAlias($alias);

        $this->assertDatabaseCount('task_execution_debugs', 0);
    }

    public function test_clear_by_fqcn_deletes_all_debug_entries(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn1 = $this->createFqcnVO(TestUniqueTask::class);
        $fqcn2 = $this->createFqcnVO(TestRecurringTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn1,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Unique task')
        );

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn2,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Recurring task')
        );

        $this->repository->clearByFqcn($fqcn1);

        $remaining = $this->repository->findByFqcn($fqcn1);
        $this->assertCount(0, $remaining);

        $other = $this->repository->findByFqcn($fqcn2);
        $this->assertCount(1, $other);
    }

    public function test_count_by_alias_returns_count(): void
    {
        $uuid1 = $this->generateUuid();
        $uuid2 = $this->generateUuid();
        $alias1 = $this->createAliasVO($uuid1);
        $alias2 = $this->createAliasVO($uuid2);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias1,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('First execution')
        );

        $this->repository->addDebug(
            alias: $alias1,
            fqcn: $fqcn,
            status: ExecutionStatus::FAILED,
            info: new DescriptionVO('Second execution')
        );

        $this->repository->addDebug(
            alias: $alias2,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Other task')
        );

        $count = $this->repository->countByAlias($alias1);

        $this->assertEquals(2, $count->getValue());
    }

    public function test_count_by_alias_returns_zero_when_no_entries(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $count = $this->repository->countByAlias($alias);

        $this->assertEquals(0, $count->getValue());
    }

    public function test_count_by_fqcn_returns_count(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('First execution')
        );

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::FAILED,
            info: new DescriptionVO('Second execution')
        );

        $count = $this->repository->countByFqcn($fqcn);

        $this->assertEquals(2, $count->getValue());
    }

    public function test_count_by_status_returns_count(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Success task')
        );

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::FAILED,
            info: new DescriptionVO('Failed task')
        );

        $count = $this->repository->countByStatus(ExecutionStatus::SUCCEEDED);

        $this->assertEquals(1, $count->getValue());
    }

    public function test_apply_filters_with_alias(): void
    {
        $uuid1 = $this->generateUuid();
        $uuid2 = $this->generateUuid();
        $alias1 = $this->createAliasVO($uuid1);
        $alias2 = $this->createAliasVO($uuid2);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias1,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('First task')
        );

        $this->repository->addDebug(
            alias: $alias2,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Second task')
        );

        $filters = TaskExecutionDebugFiltersRecord::from([
            'alias' => $alias1,
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals('unique@'.$uuid1, $results->first()->getAlias()->getValue());
    }

    public function test_apply_filters_with_fqcn(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn1 = $this->createFqcnVO(TestUniqueTask::class);
        $fqcn2 = $this->createFqcnVO(TestRecurringTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn1,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Unique task')
        );

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn2,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Recurring task')
        );

        $filters = TaskExecutionDebugFiltersRecord::from([
            'fqcn' => $fqcn1,
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        $this->assertCount(1, $results);
        $this->assertEquals($fqcn1->getValue(), $results->first()->getFqcn()->getValue());
    }

    public function test_model_to_record_converts_model_to_record(): void
    {
        $uuid = $this->generateUuid();
        $alias = $this->createAliasVO($uuid);
        $fqcn = $this->createFqcnVO(TestUniqueTask::class);

        $this->repository->addDebug(
            alias: $alias,
            fqcn: $fqcn,
            status: ExecutionStatus::SUCCEEDED,
            info: new DescriptionVO('Task executed')
        );

        $model = TaskExecutionDebug::first();
        $this->assertNotNull($model);

        $record = $this->repository->modelToRecord($model);

        $this->assertEquals($model->getId(), $record->id);
        $this->assertEquals($model->getAlias()->getValue(), $record->alias->getValue());
        $this->assertEquals($model->getFqcn()->getValue(), $record->fqcn->getValue());
        $this->assertEquals($model->getStatus()->value, $record->status->value);
        $this->assertEquals($model->getStartedAt()->getValue(), $record->started_at->getValue());
        $this->assertEquals($model->getEndedAt()->getValue(), $record->ended_at->getValue());
        $this->assertEquals($model->getData()->toArray(), $record->data->toArray());
    }
}
