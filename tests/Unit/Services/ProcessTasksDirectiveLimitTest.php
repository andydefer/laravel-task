<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Records\DirectiveResponseRecord;
use AndyDefer\Directive\Testing\InteractsWithDirectives;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\Tests\UnitTestCase;
use AndyDefer\Task\ValueObjects\Iso8601DateTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
final class ProcessTasksDirectiveLimitTest extends UnitTestCase
{
    use InteractsWithDirectives;

    private TaskBatchService&MockObject $batch;

    private ProcessTasksDirective $directive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initDirectiveTesting();

        $this->batch = $this->createMock(TaskBatchService::class);
        $this->directive = new ProcessTasksDirective($this->interaction, $this->batch);
        $this->registerDirective($this->directive);
    }

    protected function tearDown(): void
    {
        $this->destroyDirectiveTesting();
        parent::tearDown();
    }

    private function runDirectiveWithArgs(array $arguments = []): DirectiveResponseRecord
    {
        return $this->runDirective(ProcessTasksDirective::class, $arguments);
    }

    private function createSuccessResult(): BatchResultRecord
    {
        return new BatchResultRecord(
            startedAt: new Iso8601DateTime,
            uniqueSuccess: 1,
            uniqueFailed: 0,
            recurringSuccess: 0,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection,
            recurringResults: new RecurringResultCollection,
            errors: new TaskErrorCollection,
        );
    }

    private function createSuccessRecurringResult(): BatchResultRecord
    {
        return new BatchResultRecord(
            startedAt: new Iso8601DateTime,
            uniqueSuccess: 0,
            uniqueFailed: 0,
            recurringSuccess: 1,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection,
            recurringResults: new RecurringResultCollection,
            errors: new TaskErrorCollection,
        );
    }

    public function test_execute_with_limit_passes_limit_to_batch(): void
    {
        // Arrange
        $result = $this->createSuccessResult();

        $this->batch->expects($this->once())
            ->method('process')
            ->with(5)
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs(['--limit=5']);

        // Assert
        $this->assertSame(ExitCode::SUCCESS, $response->exitCode);
    }

    public function test_execute_with_limit_zero_returns_invalid_argument(): void
    {
        // Act
        $response = $this->runDirectiveWithArgs(['--limit=0']);

        // Assert
        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exitCode);
        $this->assertStringContainsString('Limit must be a positive integer', $response->output);
    }

    public function test_execute_with_limit_negative_returns_invalid_argument(): void
    {
        // Act
        $response = $this->runDirectiveWithArgs(['--limit=-5']);

        // Assert
        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exitCode);
        $this->assertStringContainsString('Limit must be a positive integer', $response->output);
    }

    public function test_execute_with_limit_and_unique_only_passes_limit(): void
    {
        // Arrange
        $result = $this->createSuccessResult();

        $this->batch->expects($this->once())
            ->method('processUniqueOnly')
            ->with(3)
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs(['--unique-only', '--limit=3']);

        // Assert
        $this->assertSame(ExitCode::SUCCESS, $response->exitCode);
    }

    public function test_execute_with_limit_and_recurring_only_passes_limit(): void
    {
        // Arrange
        $result = $this->createSuccessRecurringResult();

        $this->batch->expects($this->once())
            ->method('processRecurringOnly')
            ->with(3)
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs(['--recurring-only', '--limit=3']);

        // Assert
        $this->assertSame(ExitCode::SUCCESS, $response->exitCode);
    }

    public function test_execute_with_non_numeric_limit(): void
    {
        // Act
        $response = $this->runDirectiveWithArgs(['--limit=abc']);

        // Assert
        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exitCode);
    }

    public function test_execute_with_limit_and_verbose(): void
    {
        // Arrange
        $result = $this->createSuccessResult();

        $this->batch->expects($this->once())
            ->method('process')
            ->with(10)
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs(['--limit=10', '--verbose']);

        // Assert
        $this->assertSame(ExitCode::SUCCESS, $response->exitCode);
        $this->assertStringContainsString('Limit: 10 tasks', $response->output);
    }
}
