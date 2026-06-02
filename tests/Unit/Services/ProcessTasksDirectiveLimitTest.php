<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Testing\InteractsWithDirectives;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Services\BatchResult;
use AndyDefer\Task\Services\TaskBatch;
use AndyDefer\Task\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
final class ProcessTasksDirectiveLimitTest extends UnitTestCase
{
    use InteractsWithDirectives;

    private TaskBatch&MockObject $batch;
    private ProcessTasksDirective $directive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initDirectiveTesting();

        $this->batch = $this->createMock(TaskBatch::class);
        $this->directive = new ProcessTasksDirective($this->interaction, $this->batch);
        $this->registerDirective($this->directive);
    }

    protected function tearDown(): void
    {
        $this->destroyDirectiveTesting();
        parent::tearDown();
    }

    private function runDirectiveWithArgs(array $arguments = []): \AndyDefer\Directive\Records\DirectiveResponseRecord
    {
        return $this->runDirective(ProcessTasksDirective::class, $arguments);
    }

    public function test_execute_with_limit_passes_limit_to_batch(): void
    {
        // Arrange
        $result = new BatchResult();
        $result->addUniqueTask('task-1', true);

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
        $result = new BatchResult();
        $result->addUniqueTask('task-1', true);

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
        $result = new BatchResult();
        $result->addRecurringTask('recurring-1', true);

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
        $result = new BatchResult();
        $result->addUniqueTask('task-1', true);

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
