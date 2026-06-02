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
final class ProcessTasksDirectiveTest extends UnitTestCase
{
    use InteractsWithDirectives;

    private TaskBatch&MockObject $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initDirectiveTesting();

        $this->batch = $this->createMock(TaskBatch::class);

        // Get the interaction service from the trait
        $interaction = $this->interaction;

        $directive = new ProcessTasksDirective($interaction, $this->batch);
        $this->registerDirective($directive);
    }

    protected function tearDown(): void
    {
        $this->destroyDirectiveTesting();
        parent::tearDown();
    }

    /**
     * Helper to run directive with arguments and get response
     */
    private function runDirectiveWithArgs(array $arguments = []): \AndyDefer\Directive\Records\DirectiveResponseRecord
    {
        return $this->runDirective(ProcessTasksDirective::class, $arguments);
    }

    public function test_get_signature_returns_correct_string(): void
    {
        // Arrange & Act
        $interaction = $this->interaction;
        $directive = new ProcessTasksDirective($interaction, $this->batch);
        $signature = $directive->getSignature();

        // Assert
        $this->assertStringContainsString('process-tasks', $signature);
        $this->assertStringContainsString('--unique-only', $signature);
        $this->assertStringContainsString('--recurring-only', $signature);
        $this->assertStringContainsString('--verbose', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        // Arrange & Act
        $interaction = $this->interaction;
        $directive = new ProcessTasksDirective($interaction, $this->batch);
        $description = $directive->getDescription();

        // Assert
        $this->assertIsString($description);
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('batch', $description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        // Arrange & Act
        $interaction = $this->interaction;
        $directive = new ProcessTasksDirective($interaction, $this->batch);
        $aliases = $directive->getAliases();

        // Assert
        $this->assertTrue($aliases->contains('task:process'));
        $this->assertTrue($aliases->contains('tasks:process'));
        $this->assertSame(2, $aliases->count());
    }

    public function test_execute_processes_all_tasks_by_default(): void
    {
        // Arrange
        $result = new BatchResult();
        $result->addUniqueTask('task-1', true);

        $this->batch->expects($this->once())
            ->method('process')
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs();

        // Assert
        $this->assertSame(ExitCode::SUCCESS, $response->exitCode);
    }

    public function test_execute_with_unique_only_flag(): void
    {
        // Arrange
        $result = new BatchResult();
        $result->addUniqueTask('task-1', true);

        $this->batch->expects($this->once())
            ->method('processUniqueOnly')
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs(['--unique-only']);

        // Assert
        $this->assertSame(ExitCode::SUCCESS, $response->exitCode);
    }

    public function test_execute_with_recurring_only_flag(): void
    {
        // Arrange
        $result = new BatchResult();
        $result->addRecurringTask('recurring-1', true);

        $this->batch->expects($this->once())
            ->method('processRecurringOnly')
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs(['--recurring-only']);

        // Assert
        $this->assertSame(ExitCode::SUCCESS, $response->exitCode);
    }

    public function test_execute_returns_failure_when_tasks_fail(): void
    {
        // Arrange
        $result = new BatchResult();
        $result->addUniqueTask('task-1', false, 'Failed');

        $this->batch->expects($this->once())
            ->method('process')
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs();

        // Assert
        $this->assertSame(ExitCode::FAILURE, $response->exitCode);
    }

    public function test_execute_returns_failure_when_some_tasks_succeed(): void
    {
        // Arrange
        $result = new BatchResult();
        $result->addUniqueTask('task-1', true);
        $result->addUniqueTask('task-2', false, 'Failed');

        $this->batch->expects($this->once())
            ->method('process')
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs();

        // Assert
        $this->assertSame(ExitCode::FAILURE, $response->exitCode);
    }

    public function test_execute_with_verbose_flag_shows_errors(): void
    {
        // Arrange
        $result = new BatchResult();
        $result->addUniqueTask('task-1', false, 'Something went wrong');

        $this->batch->expects($this->once())
            ->method('process')
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs(['--verbose']);

        // Assert
        $this->assertSame(ExitCode::FAILURE, $response->exitCode);
        $this->assertStringContainsString('Failed Tasks', $response->output);
        $this->assertStringContainsString('task-1', $response->output);
        $this->assertStringContainsString('Something went wrong', $response->output);
    }

    public function test_execute_with_both_flags_returns_invalid_argument(): void
    {
        // Act
        $response = $this->runDirectiveWithArgs(['--unique-only', '--recurring-only']);

        // Assert
        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exitCode);
        $this->assertStringContainsString('Cannot use both', $response->output);
    }

    public function test_execute_output_contains_batch_results(): void
    {
        // Arrange
        $result = new BatchResult();
        $result->addUniqueTask('task-1', true);
        $result->addRecurringTask('recurring-1', true);

        $this->batch->expects($this->once())
            ->method('process')
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs();

        // Assert
        $this->assertStringContainsString('Batch Results', $response->output);
        $this->assertStringContainsString('Unique tasks:', $response->output);
        $this->assertStringContainsString('Recurring tasks:', $response->output);
        $this->assertStringContainsString('Total:', $response->output);
    }

    public function test_execute_output_without_verbose_does_not_show_errors(): void
    {
        // Arrange
        $result = new BatchResult();
        $result->addUniqueTask('task-1', false, 'Connection timeout');

        $this->batch->expects($this->once())
            ->method('process')
            ->willReturn($result);

        // Act
        $response = $this->runDirectiveWithArgs();

        // Assert
        $this->assertStringContainsString('Batch Results', $response->output);
        $this->assertStringNotContainsString('Failed Tasks', $response->output);
        $this->assertStringNotContainsString('Connection timeout', $response->output);
    }
}
