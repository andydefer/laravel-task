<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Collections\TaskExecutionDebugRecordCollection;
use AndyDefer\Task\Contracts\Services\TaskExecutionDebugServiceInterface;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Services\TaskExecutionDebugService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskTypeVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Ramsey\Uuid\Uuid;

final class TaskExecutionDebugServiceTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private TaskExecutionDebugServiceInterface $service;

    private TaskExecutionDebugRepository $repository;

    private const TEST_TASK_CLASS = TestTask::class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new TaskExecutionDebugRepository;
        $this->service = new TaskExecutionDebugService(
            $this->repository,
            App::make(LoggerInterface::class)
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Carbon::setTestNow(null);
    }

    // ==================== HELPERS ====================

    private function generateAliasFromName(string $name): TaskAliasVO
    {
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, $name);

        return new TaskAliasVO(
            new TaskTypeVO('unique'),
            $uuid->toString()
        );
    }

    private function generateRecurringAliasFromName(string $name): TaskAliasVO
    {
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, $name);

        return new TaskAliasVO(
            new TaskTypeVO('recurring'),  // ✅ Type 'recurring'
            $uuid->toString()
        );
    }

    private function generateFqcn(string $class): TaskFqcnVO
    {
        return new TaskFqcnVO($class);
    }

    // ==================== TESTS ADD DEBUG ====================

    public function test_add_debug_creates_record(): void
    {
        $alias = $this->generateAliasFromName('test-uuid-123');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $result = $this->service->addDebug(
            $alias,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Task executed successfully')
        );

        $this->assertTrue($result);

        $results = $this->service->findByAlias($alias);
        $this->assertCount(1, $results);

        $first = $results->first();
        $this->assertEquals('succeeded', $first->status->value);
        $this->assertEquals('Task executed successfully', $first->data->toArray()['info'] ?? '');
        $this->assertEquals($alias->getValue(), $first->alias->getValue());
    }

    // ==================== TESTS FIND BY ALIAS ====================

    public function test_find_by_alias_returns_collection(): void
    {
        $alias = $this->generateAliasFromName('test-uuid-456');

        $this->service->addDebug(
            $alias,
            $this->generateFqcn(self::TEST_TASK_CLASS),
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('First execution')
        );

        $this->service->addDebug(
            $alias,
            $this->generateFqcn(self::TEST_TASK_CLASS),
            ExecutionStatus::FAILED,
            new DescriptionVO('Second execution')
        );

        $results = $this->service->findByAlias($alias);

        $this->assertInstanceOf(TaskExecutionDebugRecordCollection::class, $results);
        $this->assertCount(2, $results);

        foreach ($results as $result) {
            $this->assertEquals($alias->getValue(), $result->alias->getValue());
        }
    }

    public function test_find_by_alias_returns_empty_collection_when_not_found(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $results = $this->service->findByAlias($alias);

        $this->assertInstanceOf(TaskExecutionDebugRecordCollection::class, $results);
        $this->assertCount(0, $results);
    }

    // ==================== TESTS FIND BY RECURRING TASK ====================

    public function test_find_by_recurring_task_returns_collection(): void
    {
        // ✅ Utiliser un alias avec le type 'recurring'
        $alias = $this->generateRecurringAliasFromName('test-alias');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $this->service->addDebugForRecurringTask(
            $alias,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Recurring task executed')
        );

        $results = $this->service->findByRecurringTask($alias);

        $this->assertInstanceOf(TaskExecutionDebugRecordCollection::class, $results);
        $this->assertCount(1, $results);

        $first = $results->first();
        $this->assertEquals($alias->getValue(), $first->alias->getValue());
        $this->assertEquals('recurring', $first->alias->getType()->getValue());
    }

    public function test_find_by_recurring_task_returns_empty_when_not_found(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $results = $this->service->findByRecurringTask($alias);

        $this->assertInstanceOf(TaskExecutionDebugRecordCollection::class, $results);
        $this->assertCount(0, $results);
    }

    // ==================== TESTS FIND BY UNIQUE TASK ====================

    public function test_find_by_unique_task_returns_collection(): void
    {
        $alias = $this->generateAliasFromName('550e8400-e29b-41d4-a716-446655440000');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $this->service->addDebugForUniqueTask(
            $alias,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Unique task executed')
        );

        $results = $this->service->findByUniqueTask($alias);

        $this->assertInstanceOf(TaskExecutionDebugRecordCollection::class, $results);
        $this->assertCount(1, $results);

        $first = $results->first();
        $this->assertEquals($alias->getValue(), $first->alias->getValue());
        $this->assertEquals('unique', $first->alias->getType()->getValue());
    }

    public function test_find_by_unique_task_returns_empty_when_not_found(): void
    {
        $alias = $this->generateAliasFromName('non-existent-uuid');
        $results = $this->service->findByUniqueTask($alias);

        $this->assertInstanceOf(TaskExecutionDebugRecordCollection::class, $results);
        $this->assertCount(0, $results);
    }

    // ==================== TESTS CLEAR TASK DEBUG ====================

    public function test_clear_task_debug_deletes_all_entries(): void
    {
        $alias1 = $this->generateAliasFromName('550e8400-e29b-41d4-a716-446655440000');
        $alias2 = $this->generateAliasFromName('other-alias');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $this->service->addDebugForUniqueTask(
            $alias1,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('First execution')
        );

        $this->service->addDebugForUniqueTask(
            $alias1,
            $fqcn,
            ExecutionStatus::FAILED,
            new DescriptionVO('Second execution')
        );

        $this->service->addDebugForRecurringTask(
            $alias2,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Other task')
        );

        $this->service->clearTaskDebug($alias1);

        $remaining = $this->service->findByUniqueTask($alias1);
        $this->assertCount(0, $remaining);

        $other = $this->service->findByRecurringTask($alias2);
        $this->assertCount(1, $other);
    }

    public function test_clear_task_debug_does_nothing_when_no_entries(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $result = $this->service->clearTaskDebug($alias);
        $this->assertTrue($result);

        $this->assertDatabaseCount('task_execution_debugs', 0);
    }

    // ==================== TESTS COUNT TASK DEBUG ====================

    public function test_count_task_debug_returns_count(): void
    {
        $alias = $this->generateAliasFromName('550e8400-e29b-41d4-a716-446655440000');
        $alias2 = $this->generateAliasFromName('other-alias');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $this->service->addDebugForUniqueTask(
            $alias,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('First execution')
        );

        $this->service->addDebugForUniqueTask(
            $alias,
            $fqcn,
            ExecutionStatus::FAILED,
            new DescriptionVO('Second execution')
        );

        $this->service->addDebugForRecurringTask(
            $alias2,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Other task')
        );

        $count = $this->service->countTaskDebug($alias);

        $this->assertEquals(2, $count->getValue());
    }

    public function test_count_task_debug_returns_zero_when_no_entries(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $count = $this->service->countTaskDebug($alias);

        $this->assertEquals(0, $count->getValue());
    }

    // ==================== TESTS ORDERING ====================

    public function test_find_by_alias_orders_by_created_at_desc(): void
    {
        $alias = $this->generateAliasFromName('550e8400-e29b-41d4-a716-446655440000');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $this->service->addDebugForUniqueTask(
            $alias,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('First execution')
        );

        // ✅ Attendre plus longtemps pour être sûr d'avoir une différence
        sleep(2);

        $this->service->addDebugForUniqueTask(
            $alias,
            $fqcn,
            ExecutionStatus::FAILED,
            new DescriptionVO('Second execution')
        );

        $results = $this->service->findByUniqueTask($alias);

        $this->assertCount(2, $results);

        $first = $results->first();
        $last = $results->last();

        // ✅ Vérifier que la première entrée est plus récente (ou égale en timestamp)
        $this->assertGreaterThanOrEqual(
            $last->started_at->getTimestamp(),
            $first->started_at->getTimestamp(),
            'First entry should have a more recent started_at'
        );
    }

    // ==================== TESTS MULTIPLE TASKS ====================

    public function test_can_handle_multiple_tasks_independently(): void
    {
        $alias1 = $this->generateAliasFromName('alias-1');
        $alias2 = $this->generateAliasFromName('alias-2');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $this->service->addDebugForRecurringTask(
            $alias1,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Task 1 succeeded')
        );

        sleep(1);

        $this->service->addDebugForRecurringTask(
            $alias2,
            $fqcn,
            ExecutionStatus::FAILED,
            new DescriptionVO('Task 2 failed')
        );

        sleep(1);

        $this->service->addDebugForRecurringTask(
            $alias1,
            $fqcn,
            ExecutionStatus::FAILED,
            new DescriptionVO('Task 1 failed on retry')
        );

        $task1Results = $this->service->findByRecurringTask($alias1);
        $task2Results = $this->service->findByRecurringTask($alias2);

        $this->assertCount(2, $task1Results);
        $this->assertCount(1, $task2Results);

        foreach ($task1Results as $result) {
            $this->assertEquals($alias1->getValue(), $result->alias->getValue());
        }

        foreach ($task2Results as $result) {
            $this->assertEquals($alias2->getValue(), $result->alias->getValue());
        }
    }

    // ==================== TESTS HAS DEBUG ====================

    public function test_has_debug_returns_true_when_debug_exists(): void
    {
        $alias = $this->generateAliasFromName('test-has-debug');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $this->service->addDebug(
            $alias,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Task executed')
        );

        $this->assertTrue($this->service->hasDebug($alias));
    }

    public function test_has_debug_returns_false_when_no_debug_exists(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $this->assertFalse($this->service->hasDebug($alias));
    }

    // ==================== TESTS HAS DEBUG BY FQCN ====================

    public function test_has_debug_by_fqcn_returns_true_when_debug_exists(): void
    {
        $alias = $this->generateAliasFromName('test-has-debug-fqcn');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $this->service->addDebug(
            $alias,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Task executed')
        );

        $this->assertTrue($this->service->hasDebugByFqcn($fqcn));
    }

    public function test_has_debug_by_fqcn_returns_false_when_no_debug_exists(): void
    {
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);
        $this->assertFalse($this->service->hasDebugByFqcn($fqcn));
    }

    // ==================== TESTS CLEAR BY FQCN ====================

    public function test_clear_task_debug_by_fqcn_deletes_all_entries(): void
    {
        $alias1 = $this->generateAliasFromName('550e8400-e29b-41d4-a716-446655440000');
        $alias2 = $this->generateAliasFromName('550e8400-e29b-41d4-a716-446655440001');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $this->service->addDebug(
            $alias1,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Task 1 executed')
        );

        $this->service->addDebug(
            $alias2,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Task 2 executed')
        );

        $this->service->clearTaskDebugByFqcn($fqcn);

        $remaining1 = $this->service->findByAlias($alias1);
        $remaining2 = $this->service->findByAlias($alias2);

        $this->assertCount(0, $remaining1);
        $this->assertCount(0, $remaining2);
    }

    public function test_clear_task_debug_by_fqcn_does_nothing_when_no_entries(): void
    {
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);
        $result = $this->service->clearTaskDebugByFqcn($fqcn);
        $this->assertTrue($result);

        $this->assertDatabaseCount('task_execution_debugs', 0);
    }

    // ==================== TESTS COUNT BY FQCN ====================

    public function test_count_task_debug_by_fqcn_returns_count(): void
    {
        $alias1 = $this->generateAliasFromName('550e8400-e29b-41d4-a716-446655440000');
        $alias2 = $this->generateAliasFromName('550e8400-e29b-41d4-a716-446655440001');
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);

        $this->service->addDebug(
            $alias1,
            $fqcn,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Task 1 executed')
        );

        $this->service->addDebug(
            $alias2,
            $fqcn,
            ExecutionStatus::FAILED,
            new DescriptionVO('Task 2 failed')
        );

        $count = $this->service->countTaskDebugByFqcn($fqcn);

        $this->assertEquals(2, $count->getValue());
    }

    public function test_count_task_debug_by_fqcn_returns_zero_when_no_entries(): void
    {
        $fqcn = $this->generateFqcn(self::TEST_TASK_CLASS);
        $count = $this->service->countTaskDebugByFqcn($fqcn);

        $this->assertEquals(0, $count->getValue());
    }
}
