<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\Task\Contracts\Services\TaskExecutionDebugServiceInterface;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Services\TaskExecutionDebugService;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Collection;

final class TaskExecutionDebugServiceTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private TaskExecutionDebugServiceInterface $service;

    private TaskExecutionDebugRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runDatabaseMigrations();

        $this->repository = new TaskExecutionDebugRepository;
        $this->service = new TaskExecutionDebugService($this->repository);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ==================== TESTS ADD DEBUG ====================

    public function test_add_debug_creates_record(): void
    {
        $this->service->addDebug(
            'unique',
            'test-uuid-123',
            'succeeded',
            'Task executed successfully'
        );

        $this->assertDatabaseHas('task_execution_debugs', [
            'task_type' => 'unique',
            'task_identifier' => 'test-uuid-123',
        ]);

        $results = $this->service->findByTask('unique', 'test-uuid-123');
        $this->assertCount(1, $results);

        $first = $results->first();
        $data = $first->getData();
        $this->assertEquals('succeeded', $data->status);
        $this->assertEquals('Task executed successfully', $data->info);
    }

    // ==================== TESTS FIND BY TASK ====================

    public function test_find_by_task_returns_collection(): void
    {
        $this->service->addDebug(
            'unique',
            'test-uuid-456',
            'succeeded',
            'First execution'
        );

        $this->service->addDebug(
            'unique',
            'test-uuid-456',
            'failed',
            'Second execution'
        );

        $results = $this->service->findByTask('unique', 'test-uuid-456');

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(2, $results);

        foreach ($results as $result) {
            $this->assertEquals('unique', $result->getTaskType());
            $this->assertEquals('test-uuid-456', $result->getTaskIdentifier());
        }
    }

    public function test_find_by_task_returns_empty_collection_when_not_found(): void
    {
        $results = $this->service->findByTask('unique', 'non-existent');

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(0, $results);
    }

    // ==================== TESTS FIND BY RECURRING TASK ====================

    public function test_find_by_recurring_task_returns_collection(): void
    {
        $this->service->addDebugForRecurringTask(
            'test-alias',
            'succeeded',
            'Recurring task executed'
        );

        $results = $this->service->findByRecurringTask('test-alias');

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(1, $results);

        $first = $results->first();
        $this->assertEquals('recurring', $first->getTaskType());
        $this->assertEquals('test-alias', $first->getTaskIdentifier());
    }

    public function test_find_by_recurring_task_returns_empty_when_not_found(): void
    {
        $results = $this->service->findByRecurringTask('non-existent');

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(0, $results);
    }

    // ==================== TESTS FIND BY UNIQUE TASK ====================

    public function test_find_by_unique_task_returns_collection(): void
    {
        $this->service->addDebugForUniqueTask(
            '550e8400-e29b-41d4-a716-446655440000',
            'succeeded',
            'Unique task executed'
        );

        $results = $this->service->findByUniqueTask('550e8400-e29b-41d4-a716-446655440000');

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(1, $results);

        $first = $results->first();
        $this->assertEquals('unique', $first->getTaskType());
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $first->getTaskIdentifier());
    }

    public function test_find_by_unique_task_returns_empty_when_not_found(): void
    {
        $results = $this->service->findByUniqueTask('non-existent-uuid');

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(0, $results);
    }

    // ==================== TESTS ADD DEBUG FOR RECURRING TASK ====================

    public function test_add_debug_for_recurring_task_creates_record(): void
    {
        $this->service->addDebugForRecurringTask(
            'test-alias-debug',
            'failed',
            'Recurring task failed'
        );

        $this->assertDatabaseHas('task_execution_debugs', [
            'task_type' => 'recurring',
            'task_identifier' => 'test-alias-debug',
        ]);

        $results = $this->service->findByRecurringTask('test-alias-debug');
        $this->assertCount(1, $results);

        $first = $results->first();
        $data = $first->getData();
        $this->assertEquals('failed', $data->status);
        $this->assertEquals('Recurring task failed', $data->info);
    }

    // ==================== TESTS ADD DEBUG FOR UNIQUE TASK ====================

    public function test_add_debug_for_unique_task_creates_record(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440001';

        $this->service->addDebugForUniqueTask(
            $uuid,
            'started',
            'Unique task started'
        );

        $this->assertDatabaseHas('task_execution_debugs', [
            'task_type' => 'unique',
            'task_identifier' => $uuid,
        ]);

        $results = $this->service->findByUniqueTask($uuid);
        $this->assertCount(1, $results);

        $first = $results->first();
        $data = $first->getData();
        $this->assertEquals('started', $data->status);
        $this->assertEquals('Unique task started', $data->info);
    }

    // ==================== TESTS CLEAR TASK DEBUG ====================

    public function test_clear_task_debug_deletes_all_entries(): void
    {
        $this->service->addDebugForUniqueTask(
            '550e8400-e29b-41d4-a716-446655440000',
            'succeeded',
            'First execution'
        );

        $this->service->addDebugForUniqueTask(
            '550e8400-e29b-41d4-a716-446655440000',
            'failed',
            'Second execution'
        );

        $this->service->addDebugForRecurringTask(
            'other-alias',
            'succeeded',
            'Other task'
        );

        $this->service->clearTaskDebug('unique', '550e8400-e29b-41d4-a716-446655440000');

        $remaining = $this->service->findByUniqueTask('550e8400-e29b-41d4-a716-446655440000');
        $this->assertCount(0, $remaining);

        $other = $this->service->findByRecurringTask('other-alias');
        $this->assertCount(1, $other);
    }

    public function test_clear_task_debug_does_nothing_when_no_entries(): void
    {
        $this->service->clearTaskDebug('unique', 'non-existent');

        $this->assertDatabaseCount('task_execution_debugs', 0);
    }

    // ==================== TESTS COUNT TASK DEBUG ====================

    public function test_count_task_debug_returns_count(): void
    {
        $this->service->addDebugForUniqueTask(
            '550e8400-e29b-41d4-a716-446655440000',
            'succeeded',
            'First execution'
        );

        $this->service->addDebugForUniqueTask(
            '550e8400-e29b-41d4-a716-446655440000',
            'failed',
            'Second execution'
        );

        $this->service->addDebugForRecurringTask(
            'other-alias',
            'succeeded',
            'Other task'
        );

        $count = $this->service->countTaskDebug('unique', '550e8400-e29b-41d4-a716-446655440000');

        $this->assertEquals(2, $count);
    }

    public function test_count_task_debug_returns_zero_when_no_entries(): void
    {
        $count = $this->service->countTaskDebug('unique', 'non-existent');

        $this->assertEquals(0, $count);
    }

    // ==================== TESTS ORDERING ====================

    public function test_find_by_task_orders_by_created_at_desc(): void
    {
        $this->service->addDebugForUniqueTask(
            '550e8400-e29b-41d4-a716-446655440000',
            'succeeded',
            'First execution'
        );

        sleep(1);

        $this->service->addDebugForUniqueTask(
            '550e8400-e29b-41d4-a716-446655440000',
            'failed',
            'Second execution'
        );

        $results = $this->service->findByUniqueTask('550e8400-e29b-41d4-a716-446655440000');

        $this->assertCount(2, $results);

        $first = $results->first();
        $last = $results->last();

        $this->assertTrue(
            $first->created_at->gt($last->created_at),
            'First entry should have a more recent created_at'
        );
    }

    // ==================== TESTS MULTIPLE TASKS ====================

    public function test_can_handle_multiple_tasks_independently(): void
    {

        $this->service->addDebugForRecurringTask(
            'alias-1',
            'succeeded',
            'Task 1 succeeded'
        );

        // ✅ Attendre 1 seconde pour avoir des timestamps différents
        sleep(1);

        $this->service->addDebugForRecurringTask(
            'alias-2',
            'failed',
            'Task 2 failed'
        );

        // ✅ Attendre 1 seconde pour avoir des timestamps différents
        sleep(1);

        $this->service->addDebugForRecurringTask(
            'alias-1',
            'failed',
            'Task 1 failed on retry'
        );

        $task1Results = $this->service->findByRecurringTask('alias-1');
        $task2Results = $this->service->findByRecurringTask('alias-2');

        $this->assertCount(2, $task1Results);
        $this->assertCount(1, $task2Results);

        $task1First = $task1Results->first();
        $task1Last = $task1Results->last();

        $this->assertEquals('failed', $task1First->getData()->status);
        $this->assertEquals('succeeded', $task1Last->getData()->status);

        $task2First = $task2Results->first();
        $this->assertEquals('failed', $task2First->getData()->status);

    }
}
