<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Repositories;

use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Models\TaskExecutionDebug;
use AndyDefer\Task\Records\TaskExecutionDebugFiltersRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Collection;

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

    public function test_add_debug_creates_record(): void
    {
        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'test-uuid-123',
            status: 'succeeded',
            info: 'Task executed successfully',
        );

        $this->assertDatabaseHas('task_execution_debugs', [
            'task_type' => 'unique',
            'task_identifier' => 'test-uuid-123',
        ]);

        $record = TaskExecutionDebug::first();
        $this->assertNotNull($record);
        $this->assertEquals('succeeded', $record->getData()->status);
        $this->assertEquals('Task executed successfully', $record->getData()->info);
    }

    public function test_find_by_task_returns_collection(): void
    {
        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'test-uuid-456',
            status: 'succeeded',
            info: 'First execution',
        );

        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'test-uuid-456',
            status: 'failed',
            info: 'Second execution',
        );

        $results = $this->repository->findByTask('unique', 'test-uuid-456');

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(2, $results);

        foreach ($results as $result) {
            $this->assertInstanceOf(TaskExecutionDebug::class, $result);
            $this->assertEquals('unique', $result->getTaskType());
            $this->assertEquals('test-uuid-456', $result->getTaskIdentifier());
        }
    }

    public function test_find_by_task_returns_empty_collection_when_not_found(): void
    {
        $results = $this->repository->findByTask('unique', 'non-existent');

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(0, $results);
    }

    public function test_find_by_task_orders_by_created_at_desc(): void
    {
        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'test-uuid-789',
            status: 'succeeded',
            info: 'First execution',
        );

        sleep(1);

        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'test-uuid-789',
            status: 'failed',
            info: 'Second execution',
        );

        $results = $this->repository->findByTask('unique', 'test-uuid-789');

        $this->assertCount(2, $results);

        $first = $results->first();
        $last = $results->last();

        $this->assertTrue(
            $first->created_at->gt($last->created_at),
            'First entry should have a more recent created_at'
        );
    }

    public function test_add_debug_stores_acted_at(): void
    {
        $this->repository->addDebug(
            taskType: 'recurring',
            taskIdentifier: 'test-alias-123',
            status: 'succeeded',
            info: 'Recurring task executed',
        );

        $record = TaskExecutionDebug::first();
        $this->assertNotNull($record);

        $data = $record->getData();
        $this->assertNotNull($data->acted_at);
        $this->assertIsString($data->acted_at);
    }

    // ==================== TESTS CLEAR ====================

    public function test_clear_task_debug_deletes_all_debug_entries(): void
    {
        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'test-uuid-clear',
            status: 'succeeded',
            info: 'First execution',
        );

        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'test-uuid-clear',
            status: 'failed',
            info: 'Second execution',
        );

        $this->repository->addDebug(
            taskType: 'recurring',
            taskIdentifier: 'other-task',
            status: 'succeeded',
            info: 'Other task',
        );

        $this->repository->clearTaskDebug('unique', 'test-uuid-clear');

        $remaining = $this->repository->findByTask('unique', 'test-uuid-clear');
        $this->assertCount(0, $remaining);

        $other = $this->repository->findByTask('recurring', 'other-task');
        $this->assertCount(1, $other);
    }

    public function test_clear_task_debug_does_nothing_when_no_entries(): void
    {
        $this->repository->clearTaskDebug('unique', 'non-existent');

        $this->assertDatabaseCount('task_execution_debugs', 0);
    }

    // ==================== TESTS COUNT ====================

    public function test_count_task_debug_returns_count(): void
    {
        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'test-uuid-count',
            status: 'succeeded',
            info: 'First execution',
        );

        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'test-uuid-count',
            status: 'failed',
            info: 'Second execution',
        );

        $this->repository->addDebug(
            taskType: 'recurring',
            taskIdentifier: 'other-task',
            status: 'succeeded',
            info: 'Other task',
        );

        $count = $this->repository->countTaskDebug('unique', 'test-uuid-count');

        $this->assertEquals(2, $count);
    }

    public function test_count_task_debug_returns_zero_when_no_entries(): void
    {
        $count = $this->repository->countTaskDebug('unique', 'non-existent');

        $this->assertEquals(0, $count);
    }

    // ==================== TESTS FILTERS ====================

    public function test_apply_filters_with_task_type(): void
    {
        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'uuid-1',
            status: 'succeeded',
            info: 'Unique task',
        );

        $this->repository->addDebug(
            taskType: 'recurring',
            taskIdentifier: 'alias-1',
            status: 'succeeded',
            info: 'Recurring task',
        );

        $filters = new TaskExecutionDebugFiltersRecord(
            task_type: 'unique'
        );

        $results = $this->repository->findBy(
            new FindByRecord(filters: $filters)
        );

        $this->assertCount(1, $results);
        $this->assertEquals('unique', $results->first()->getTaskType());
    }

    public function test_apply_filters_with_task_identifier(): void
    {
        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'uuid-1',
            status: 'succeeded',
            info: 'First task',
        );

        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'uuid-2',
            status: 'succeeded',
            info: 'Second task',
        );

        $filters = new TaskExecutionDebugFiltersRecord(
            task_identifier: 'uuid-1'
        );

        $results = $this->repository->findBy(
            new FindByRecord(filters: $filters)
        );

        $this->assertCount(1, $results);
        $this->assertEquals('uuid-1', $results->first()->getTaskIdentifier());
    }

    public function test_apply_filters_with_status(): void
    {
        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'uuid-1',
            status: 'succeeded',
            info: 'Success task',
        );

        $this->repository->addDebug(
            taskType: 'unique',
            taskIdentifier: 'uuid-2',
            status: 'failed',
            info: 'Failed task',
        );

        $filters = new TaskExecutionDebugFiltersRecord(
            status: ExecutionStatus::SUCCEEDED
        );

        $results = $this->repository->findBy(
            new FindByRecord(filters: $filters)
        );

        $this->assertCount(1, $results);
        $this->assertEquals('succeeded', $results->first()->getData()->status);
    }
}
