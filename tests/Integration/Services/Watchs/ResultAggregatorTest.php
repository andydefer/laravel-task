<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services\Watchs;

use AndyDefer\DomainStructures\Utils\StrictAssociative;
use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Records\CycleHistoryRecord;
use AndyDefer\Task\Records\DetailedSummaryRecord;
use AndyDefer\Task\Records\SummaryTotalsRecord;
use AndyDefer\Task\Records\SummaryTypeRecord;
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
            'type_counts' => $type === TaskType::UNIQUE
                ? new StrictAssociative(['unique' => $success])
                : new StrictAssociative(['recurring' => $success]),
            'failed_counts' => $failed > 0
                ? new StrictAssociative([$type->value => $failed])
                : null,
            'has_unique' => $type === TaskType::UNIQUE,
            'has_recurring' => $type === TaskType::RECURRING,
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
        $this->assertEmpty($this->aggregator->getCycleHistory());
    }

    public function test_start_new_cycle_increments_cycle_count(): void
    {
        $this->aggregator->startNewCycle();
        $this->assertEquals(1, $this->aggregator->getCycleCount());

        $this->aggregator->startNewCycle();
        $this->assertEquals(2, $this->aggregator->getCycleCount());
    }

    public function test_start_new_cycle_initializes_history(): void
    {
        $this->aggregator->startNewCycle();

        $history = $this->aggregator->getCycleHistory();
        $this->assertArrayHasKey(1, $history);

        /** @var CycleHistoryRecord $record */
        $record = $history[1];

        $this->assertInstanceOf(CycleHistoryRecord::class, $record);
        $this->assertEquals(0, $record->success);
        $this->assertEquals(0, $record->failed);
        $this->assertEquals(0, $record->errors);
        $this->assertEquals(0, $record->unique_success);
        $this->assertEquals(0, $record->unique_failed);
        $this->assertEquals(0, $record->recurring_success);
        $this->assertEquals(0, $record->recurring_failed);
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

        $this->assertEquals(0, $this->aggregator->getCycleCount());

        $this->aggregator->startNewCycle();
        $this->assertEquals(1, $this->aggregator->getCycleCount());
    }

    public function test_multiple_cycles_with_results(): void
    {
        // Cycle #1
        $this->aggregator->startNewCycle();
        $result1 = $this->createResultRecord(success: 3, failed: 1);
        $this->aggregator->addResults([$result1]);
        $this->assertEquals(1, $this->aggregator->getCycleCount());

        // Cycle #2
        $this->aggregator->startNewCycle();
        $result2 = $this->createResultRecord(success: 2, failed: 0);
        $this->aggregator->addResults([$result2]);
        $this->assertEquals(2, $this->aggregator->getCycleCount());

        // Totaux cumulés
        $this->assertEquals(5, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(1, $this->aggregator->getTotalFailed()->getValue());
    }

    // ==================== TESTS CYCLE HISTORY ====================

    public function test_cycle_history_stores_results_per_cycle(): void
    {
        $this->aggregator->startNewCycle();

        $result1 = $this->createResultRecord(success: 3, failed: 1, type: TaskType::UNIQUE);
        $result2 = $this->createResultRecord(success: 2, failed: 0, type: TaskType::RECURRING);

        $this->aggregator->addResults([$result1, $result2]);

        $history = $this->aggregator->getCycleHistory();

        $this->assertArrayHasKey(1, $history);

        /** @var CycleHistoryRecord $record */
        $record = $history[1];

        $this->assertInstanceOf(CycleHistoryRecord::class, $record);

        // ✅ Totaux
        $this->assertEquals(5, $record->success);
        $this->assertEquals(1, $record->failed);
        $this->assertEquals(0, $record->errors);

        // ✅ Uniques
        $this->assertEquals(3, $record->unique_success);
        $this->assertEquals(1, $record->unique_failed);

        // ✅ Récurrents
        $this->assertEquals(2, $record->recurring_success);
        $this->assertEquals(0, $record->recurring_failed);
    }

    public function test_cycle_history_maintains_multiple_cycles(): void
    {
        // Cycle #1
        $this->aggregator->startNewCycle();
        $result1 = $this->createResultRecord(success: 3, failed: 1, type: TaskType::UNIQUE);
        $this->aggregator->addResults([$result1]);

        // Cycle #2
        $this->aggregator->startNewCycle();
        $result2 = $this->createResultRecord(success: 2, failed: 0, type: TaskType::RECURRING);
        $this->aggregator->addResults([$result2]);

        $history = $this->aggregator->getCycleHistory();

        $this->assertArrayHasKey(1, $history);
        $this->assertArrayHasKey(2, $history);

        /** @var CycleHistoryRecord $record1 */
        $record1 = $history[1];
        /** @var CycleHistoryRecord $record2 */
        $record2 = $history[2];

        $this->assertInstanceOf(CycleHistoryRecord::class, $record1);
        $this->assertInstanceOf(CycleHistoryRecord::class, $record2);

        $this->assertEquals(3, $record1->success);
        $this->assertEquals(1, $record1->failed);

        $this->assertEquals(2, $record2->success);
        $this->assertEquals(0, $record2->failed);
    }

    public function test_get_cycle_success_returns_correct_value(): void
    {
        $this->aggregator->startNewCycle();
        $result = $this->createResultRecord(success: 5, failed: 2);
        $this->aggregator->addResults([$result]);

        $this->assertEquals(5, $this->aggregator->getCycleSuccess(1)->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleSuccess(2)->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleSuccess(99)->getValue());
    }

    public function test_get_cycle_failed_returns_correct_value(): void
    {
        $this->aggregator->startNewCycle();
        $result = $this->createResultRecord(success: 5, failed: 3);
        $this->aggregator->addResults([$result]);

        $this->assertEquals(3, $this->aggregator->getCycleFailed(1)->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleFailed(2)->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleFailed(99)->getValue());
    }

    public function test_get_cycle_errors_returns_correct_value(): void
    {
        $this->aggregator->startNewCycle();
        $result = $this->createResultRecord(success: 5, failed: 0, errors: 2);
        $this->aggregator->addResults([$result]);

        $this->assertEquals(2, $this->aggregator->getCycleErrors(1)->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleErrors(2)->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleErrors(99)->getValue());
    }

    public function test_get_cycle_unique_success_returns_correct_value(): void
    {
        $this->aggregator->startNewCycle();

        $uniqueResult = $this->createResultRecord(success: 5, failed: 2, type: TaskType::UNIQUE);
        $recurringResult = $this->createResultRecord(success: 3, failed: 1, type: TaskType::RECURRING);

        $this->aggregator->addResults([$uniqueResult, $recurringResult]);

        $this->assertEquals(5, $this->aggregator->getCycleUniqueSuccess(1)->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleUniqueSuccess(2)->getValue());
    }

    public function test_get_cycle_unique_failed_returns_correct_value(): void
    {
        $this->aggregator->startNewCycle();

        $uniqueResult = $this->createResultRecord(success: 5, failed: 2, type: TaskType::UNIQUE);
        $recurringResult = $this->createResultRecord(success: 3, failed: 1, type: TaskType::RECURRING);

        $this->aggregator->addResults([$uniqueResult, $recurringResult]);

        $this->assertEquals(2, $this->aggregator->getCycleUniqueFailed(1)->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleUniqueFailed(2)->getValue());
    }

    public function test_get_cycle_recurring_success_returns_correct_value(): void
    {
        $this->aggregator->startNewCycle();

        $uniqueResult = $this->createResultRecord(success: 5, failed: 2, type: TaskType::UNIQUE);
        $recurringResult = $this->createResultRecord(success: 3, failed: 1, type: TaskType::RECURRING);

        $this->aggregator->addResults([$uniqueResult, $recurringResult]);

        $this->assertEquals(3, $this->aggregator->getCycleRecurringSuccess(1)->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleRecurringSuccess(2)->getValue());
    }

    public function test_get_cycle_recurring_failed_returns_correct_value(): void
    {
        $this->aggregator->startNewCycle();

        $uniqueResult = $this->createResultRecord(success: 5, failed: 2, type: TaskType::UNIQUE);
        $recurringResult = $this->createResultRecord(success: 3, failed: 1, type: TaskType::RECURRING);

        $this->aggregator->addResults([$uniqueResult, $recurringResult]);

        $this->assertEquals(1, $this->aggregator->getCycleRecurringFailed(1)->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleRecurringFailed(2)->getValue());
    }

    public function test_get_detailed_summary_returns_correct_structure(): void
    {
        $uniqueResult = $this->createResultRecord(success: 5, failed: 2, errors: 1, type: TaskType::UNIQUE);
        $recurringResult = $this->createResultRecord(success: 3, failed: 1, errors: 0, type: TaskType::RECURRING);

        $this->aggregator->addResults([$uniqueResult, $recurringResult]);

        /** @var DetailedSummaryRecord $summary */
        $summary = $this->aggregator->getDetailedSummary();

        $this->assertInstanceOf(DetailedSummaryRecord::class, $summary);
        $this->assertInstanceOf(SummaryTotalsRecord::class, $summary->total);
        $this->assertInstanceOf(SummaryTypeRecord::class, $summary->unique);
        $this->assertInstanceOf(SummaryTypeRecord::class, $summary->recurring);

        $this->assertEquals(8, $summary->total->success);
        $this->assertEquals(3, $summary->total->failed);
        $this->assertEquals(1, $summary->total->errors);

        $this->assertEquals(5, $summary->unique->success);
        $this->assertEquals(2, $summary->unique->failed);

        $this->assertEquals(3, $summary->recurring->success);
        $this->assertEquals(1, $summary->recurring->failed);
    }

    public function test_reset_clears_all_data(): void
    {
        $this->aggregator->startNewCycle();

        $result = $this->createResultRecord(success: 5, failed: 2, errors: 1, type: TaskType::UNIQUE);
        $this->aggregator->addResults([$result]);

        $this->assertEquals(1, $this->aggregator->getCycleCount());
        $this->assertEquals(5, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(2, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(1, $this->aggregator->getTotalErrors()->getValue());

        $this->aggregator->reset();

        $this->assertEquals(0, $this->aggregator->getCycleCount());
        $this->assertEquals(0, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(0, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(0, $this->aggregator->getTotalErrors()->getValue());
        $this->assertEmpty($this->aggregator->getCycleHistory());
    }

    public function test_cycle_history_preserves_data_after_reset(): void
    {
        $this->aggregator->startNewCycle();
        $result = $this->createResultRecord(success: 5, failed: 2);
        $this->aggregator->addResults([$result]);

        $historyBefore = $this->aggregator->getCycleHistory();
        $this->assertNotEmpty($historyBefore);

        $this->aggregator->reset();
        $historyAfter = $this->aggregator->getCycleHistory();

        $this->assertEmpty($historyAfter);
        $this->assertNotSame($historyBefore, $historyAfter);
    }

    public function test_cycle_history_with_errors_in_cycle(): void
    {
        $this->aggregator->startNewCycle();
        $result = $this->createResultRecord(success: 10, failed: 0, errors: 3);
        $this->aggregator->addResults([$result]);

        $history = $this->aggregator->getCycleHistory();

        $this->assertArrayHasKey(1, $history);

        /** @var CycleHistoryRecord $record */
        $record = $history[1];

        $this->assertInstanceOf(CycleHistoryRecord::class, $record);

        $this->assertEquals(10, $record->success);
        $this->assertEquals(0, $record->failed);
        $this->assertEquals(3, $record->errors);
        $this->assertEquals(3, $this->aggregator->getCycleErrors(1)->getValue());
    }

    public function test_cycle_history_handles_empty_cycles(): void
    {
        $this->aggregator->startNewCycle();
        $this->aggregator->addResults([]);

        $history = $this->aggregator->getCycleHistory();

        $this->assertArrayHasKey(1, $history);

        /** @var CycleHistoryRecord $record */
        $record = $history[1];

        $this->assertInstanceOf(CycleHistoryRecord::class, $record);

        $this->assertEquals(0, $record->success);
        $this->assertEquals(0, $record->failed);
        $this->assertEquals(0, $record->errors);
    }

    public function test_cycle_history_handles_multiple_add_results_per_cycle(): void
    {
        $this->aggregator->startNewCycle();

        $result1 = $this->createResultRecord(success: 3, failed: 1, type: TaskType::UNIQUE);
        $result2 = $this->createResultRecord(success: 2, failed: 0, type: TaskType::UNIQUE);
        $result3 = $this->createResultRecord(success: 4, failed: 2, type: TaskType::RECURRING);

        $this->aggregator->addResults([$result1]);
        $this->aggregator->addResults([$result2]);
        $this->aggregator->addResults([$result3]);

        $history = $this->aggregator->getCycleHistory();

        $this->assertArrayHasKey(1, $history);

        /** @var CycleHistoryRecord $record */
        $record = $history[1];

        $this->assertInstanceOf(CycleHistoryRecord::class, $record);

        $this->assertEquals(9, $record->success);
        $this->assertEquals(3, $record->failed);
        $this->assertEquals(0, $record->errors);
        $this->assertEquals(5, $record->unique_success);
        $this->assertEquals(1, $record->unique_failed);
        $this->assertEquals(4, $record->recurring_success);
        $this->assertEquals(2, $record->recurring_failed);
    }
}
