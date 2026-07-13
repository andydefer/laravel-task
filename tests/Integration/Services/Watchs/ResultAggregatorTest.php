<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services\Watchs;

use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\Services\Watchs\ResultAggregator;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
use AndyDefer\Task\ValueObjects\UuidVO;

final class ResultAggregatorTest extends IntegrationTestCase
{
    private ResultAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aggregator = new ResultAggregator;
    }

    private function createResultRecord(
        int $success = 1,
        int $failed = 0,
        int $errors = 0,
        TaskType $type = TaskType::UNIQUE
    ): TaskExecutionResultRecord {
        $errorsCollection = new TaskErrorRecordCollection;

        for ($i = 0; $i < $errors; $i++) {
            $uuid = UuidVO::generate()->getValue();
            $prefix = $type === TaskType::UNIQUE ? 'unique' : 'recurring';

            $errorsCollection->add(
                TaskErrorRecord::from([
                    'alias' => $prefix.'@'.$uuid,
                    'fqcn' => FailingTask::class,
                    'description' => 'Error description '.($i + 1),
                    'context' => 'Error context '.($i + 1),
                ])
            );
        }

        return TaskExecutionResultRecord::from([
            'id' => UuidVO::generate()->getValue(),
            'started_at' => new Iso8601DateTimeVO,
            'ended_at' => new Iso8601DateTimeVO,
            'duration_ms' => new MillisecondsVO(100),
            'success' => new CounterVO($success),
            'failed' => new CounterVO($failed),
            'total' => new CounterVO($success + $failed),
            'errors' => $errorsCollection,
            'has_failures' => $failed > 0 || $errors > 0,
            'type' => $type,
        ]);
    }

    public function test_initial_state(): void
    {
        $this->assertEquals(0, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(0, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(0, $this->aggregator->getTotalErrors()->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleCount());
        $this->assertFalse($this->aggregator->hasFailures());
        $this->assertEquals(0, $this->aggregator->getUniqueSuccess()->getValue());
        $this->assertEquals(0, $this->aggregator->getUniqueFailed()->getValue());
        $this->assertEquals(0, $this->aggregator->getRecurringSuccess()->getValue());
        $this->assertEquals(0, $this->aggregator->getRecurringFailed()->getValue());
    }

    public function test_start_new_cycle_increments_cycle_count(): void
    {
        $this->aggregator->startNewCycle();
        $this->assertEquals(1, $this->aggregator->getCycleCount());

        $this->aggregator->startNewCycle();
        $this->assertEquals(2, $this->aggregator->getCycleCount());
    }

    public function test_add_results_with_unique_tasks(): void
    {
        $result = $this->createResultRecord(
            success: 5,
            failed: 2,
            errors: 1,
            type: TaskType::UNIQUE
        );

        $this->aggregator->addResults([$result]);

        $this->assertEquals(5, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(2, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(1, $this->aggregator->getTotalErrors()->getValue());
        $this->assertEquals(5, $this->aggregator->getUniqueSuccess()->getValue());
        $this->assertEquals(2, $this->aggregator->getUniqueFailed()->getValue());
        $this->assertEquals(0, $this->aggregator->getRecurringSuccess()->getValue());
        $this->assertEquals(0, $this->aggregator->getRecurringFailed()->getValue());
        $this->assertTrue($this->aggregator->hasFailures());
    }

    public function test_add_results_with_recurring_tasks(): void
    {
        $result = $this->createResultRecord(
            success: 3,
            failed: 1,
            errors: 2,
            type: TaskType::RECURRING
        );

        $this->aggregator->addResults([$result]);

        $this->assertEquals(3, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(1, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(2, $this->aggregator->getTotalErrors()->getValue());
        $this->assertEquals(0, $this->aggregator->getUniqueSuccess()->getValue());
        $this->assertEquals(0, $this->aggregator->getUniqueFailed()->getValue());
        $this->assertEquals(3, $this->aggregator->getRecurringSuccess()->getValue());
        $this->assertEquals(1, $this->aggregator->getRecurringFailed()->getValue());
        $this->assertTrue($this->aggregator->hasFailures());
    }

    public function test_add_results_with_both_task_types(): void
    {
        $uniqueResult = $this->createResultRecord(
            success: 5,
            failed: 2,
            errors: 0,
            type: TaskType::UNIQUE
        );

        $recurringResult = $this->createResultRecord(
            success: 3,
            failed: 1,
            errors: 0,
            type: TaskType::RECURRING
        );

        $this->aggregator->addResults([$uniqueResult, $recurringResult]);

        $this->assertEquals(8, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(3, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(5, $this->aggregator->getUniqueSuccess()->getValue());
        $this->assertEquals(2, $this->aggregator->getUniqueFailed()->getValue());
        $this->assertEquals(3, $this->aggregator->getRecurringSuccess()->getValue());
        $this->assertEquals(1, $this->aggregator->getRecurringFailed()->getValue());
        $this->assertTrue($this->aggregator->hasFailures());
    }

    public function test_add_multiple_results_aggregates_correctly(): void
    {
        $result1 = $this->createResultRecord(success: 3, failed: 1, type: TaskType::UNIQUE);
        $result2 = $this->createResultRecord(success: 2, failed: 0, type: TaskType::UNIQUE);
        $result3 = $this->createResultRecord(success: 4, failed: 2, type: TaskType::RECURRING);

        $this->aggregator->addResults([$result1, $result2, $result3]);

        $this->assertEquals(9, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(3, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(5, $this->aggregator->getUniqueSuccess()->getValue());
        $this->assertEquals(1, $this->aggregator->getUniqueFailed()->getValue());
        $this->assertEquals(4, $this->aggregator->getRecurringSuccess()->getValue());
        $this->assertEquals(2, $this->aggregator->getRecurringFailed()->getValue());
        $this->assertTrue($this->aggregator->hasFailures());
    }

    public function test_add_results_ignores_non_result_records(): void
    {
        $result = $this->createResultRecord(success: 3, failed: 1);
        $invalid = ['not' => 'a record'];

        $this->aggregator->addResults([$result, $invalid]);

        $this->assertEquals(3, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(1, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleCount());
    }

    public function test_has_failures_with_errors(): void
    {

        $result = $this->createResultRecord(success: 5, failed: 0, errors: 2);

        $this->aggregator->addResults([$result]);

        $this->assertTrue($this->aggregator->hasFailures());
        $this->assertEquals(2, $this->aggregator->getTotalErrors()->getValue());
    }

    public function test_has_failures_without_errors_or_failures(): void
    {
        $result = $this->createResultRecord(success: 5, failed: 0, errors: 0);

        $this->aggregator->addResults([$result]);

        $this->assertFalse($this->aggregator->hasFailures());
        $this->assertEquals(0, $this->aggregator->getTotalErrors()->getValue());
    }

    public function test_cycle_count_is_not_incremented_by_add_results(): void
    {
        $result = $this->createResultRecord(success: 5, failed: 2);

        $this->aggregator->addResults([$result]);

        // ✅ Le cycle count n'est pas incrémenté automatiquement
        $this->assertEquals(0, $this->aggregator->getCycleCount());

        // ✅ Il faut appeler startNewCycle() manuellement
        $this->aggregator->startNewCycle();
        $this->assertEquals(1, $this->aggregator->getCycleCount());
    }

    public function test_multiple_cycles_with_results(): void
    {
        // ✅ Cycle #1
        $this->aggregator->startNewCycle();
        $result1 = $this->createResultRecord(success: 3, failed: 1);
        $this->aggregator->addResults([$result1]);
        $this->assertEquals(1, $this->aggregator->getCycleCount());

        // ✅ Cycle #2
        $this->aggregator->startNewCycle();
        $result2 = $this->createResultRecord(success: 2, failed: 0);
        $this->aggregator->addResults([$result2]);
        $this->assertEquals(2, $this->aggregator->getCycleCount());

        // ✅ Totaux cumulés
        $this->assertEquals(5, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(1, $this->aggregator->getTotalFailed()->getValue());
    }

    public function test_has_failures_returns_false_when_no_failures(): void
    {
        $result = $this->createResultRecord(success: 10, failed: 0, errors: 0);

        $this->aggregator->addResults([$result]);

        $this->assertFalse($this->aggregator->hasFailures());
    }

    public function test_has_failures_returns_true_when_has_failed_tasks(): void
    {
        $result = $this->createResultRecord(success: 5, failed: 3, errors: 0);

        $this->aggregator->addResults([$result]);

        $this->assertTrue($this->aggregator->hasFailures());
    }

    public function test_has_failures_returns_true_when_has_errors(): void
    {
        $result = $this->createResultRecord(success: 5, failed: 0, errors: 1);

        $this->aggregator->addResults([$result]);

        // ✅ Correction : hasFailures() vérifie totalFailed > 0 OU totalErrors > 0
        // Donc avec errors: 1, hasFailures() retourne true
        $this->assertTrue($this->aggregator->hasFailures());
    }
}
