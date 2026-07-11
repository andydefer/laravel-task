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
    }

    public function test_add_result(): void
    {
        $result = $this->createResultRecord(success: 5, failed: 2);

        $this->aggregator->addResult($result);

        $this->assertEquals(5, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(2, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(0, $this->aggregator->getTotalErrors()->getValue());
        $this->assertEquals(1, $this->aggregator->getCycleCount());
        $this->assertTrue($this->aggregator->hasFailures());
    }

    public function test_add_multiple_results(): void
    {
        $result1 = $this->createResultRecord(success: 3, failed: 1);
        $result2 = $this->createResultRecord(success: 2, failed: 0);

        $this->aggregator->addResults([$result1, $result2]);

        $this->assertEquals(5, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(1, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(2, $this->aggregator->getCycleCount());
    }

    public function test_add_results_ignores_non_result_records(): void
    {
        $result = $this->createResultRecord(success: 3, failed: 1);
        $invalid = ['not' => 'a record'];

        $this->aggregator->addResults([$result, $invalid]);

        $this->assertEquals(3, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(1, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(1, $this->aggregator->getCycleCount());
    }

    public function test_has_failures_with_errors(): void
    {
        $result = $this->createResultRecord(success: 5, failed: 0, errors: 2);

        $this->aggregator->addResult($result);

        $this->assertTrue($this->aggregator->hasFailures());
        $this->assertEquals(2, $this->aggregator->getTotalErrors()->getValue());
    }

    public function test_has_failures_without_errors_or_failures(): void
    {
        $result = $this->createResultRecord(success: 5, failed: 0, errors: 0);

        $this->aggregator->addResult($result);

        $this->assertFalse($this->aggregator->hasFailures());
        $this->assertEquals(0, $this->aggregator->getTotalErrors()->getValue());
    }

    public function test_reset(): void
    {
        $result = $this->createResultRecord(success: 5, failed: 2);

        $this->aggregator->addResult($result);
        $this->aggregator->reset();

        $this->assertEquals(0, $this->aggregator->getTotalSuccess()->getValue());
        $this->assertEquals(0, $this->aggregator->getTotalFailed()->getValue());
        $this->assertEquals(0, $this->aggregator->getTotalErrors()->getValue());
        $this->assertEquals(0, $this->aggregator->getCycleCount());
        $this->assertFalse($this->aggregator->hasFailures());
    }

    public function test_to_loop_result_record(): void
    {
        $result1 = $this->createResultRecord(success: 3, failed: 1);
        $result2 = $this->createResultRecord(success: 2, failed: 0);

        $this->aggregator->addResults([$result1, $result2]);

        $loopResult = $this->aggregator->toLoopResultRecord();

        $this->assertEquals(2, $loopResult->cycle_count->getValue());
        $this->assertEquals(5, $loopResult->total_success->getValue());
        $this->assertEquals(1, $loopResult->total_failed->getValue());
        $this->assertTrue($loopResult->has_errors);
    }

    public function test_chainable_methods(): void
    {
        $result = $this->createResultRecord(success: 3, failed: 1);

        $return = $this->aggregator
            ->addResult($result)
            ->addResults([])
            ->reset();

        $this->assertSame($this->aggregator, $return);
    }
}
