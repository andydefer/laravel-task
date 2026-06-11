<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\Iso8601DateTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
final class ProcessTasksDirectiveTest extends IntegrationTestCase  // ← IntegrationTestCase, pas UnitTestCase !
{
    private DirectiveTestingService $service;
    private TaskBatchService&MockObject $batch;

    protected function setUp(): void
    {
        parent::setUp();

        // ✅ Service en mode intégré AVEC l'application
        $this->service = new DirectiveTestingService($this->app);

        $this->batch = $this->createMock(TaskBatchService::class);

        // ✅ Remplacer le service dans le conteneur par notre mock
        $this->app->instance(TaskBatchService::class, $this->batch);
    }

    protected function tearDown(): void
    {
        $this->service->destroy();
        parent::tearDown();
    }

    // ==================== Helper Methods ====================

    private function getDirective(): ProcessTasksDirective
    {
        return $this->app->make(ProcessTasksDirective::class);
    }

    private function createSuccessUniqueResult(): BatchResultRecord
    {
        return new BatchResultRecord(
            startedAt: new Iso8601DateTime(),
            uniqueSuccess: 1,
            uniqueFailed: 0,
            recurringSuccess: 0,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection(),
            recurringResults: new RecurringResultCollection(),
            errors: new TaskErrorCollection(),
        );
    }

    private function createSuccessRecurringResult(): BatchResultRecord
    {
        return new BatchResultRecord(
            startedAt: new Iso8601DateTime(),
            uniqueSuccess: 0,
            uniqueFailed: 0,
            recurringSuccess: 1,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection(),
            recurringResults: new RecurringResultCollection(),
            errors: new TaskErrorCollection(),
        );
    }

    private function createFailureResult(): BatchResultRecord
    {
        $errors = new TaskErrorCollection();
        $errors->add(new TaskErrorRecord('task-1', 'Failed'));

        return new BatchResultRecord(
            startedAt: new Iso8601DateTime(),
            uniqueSuccess: 0,
            uniqueFailed: 1,
            recurringSuccess: 0,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection(),
            recurringResults: new RecurringResultCollection(),
            errors: $errors,
        );
    }

    private function createMixedResult(): BatchResultRecord
    {
        $errors = new TaskErrorCollection();
        $errors->add(new TaskErrorRecord('task-2', 'Failed'));

        return new BatchResultRecord(
            startedAt: new Iso8601DateTime(),
            uniqueSuccess: 1,
            uniqueFailed: 1,
            recurringSuccess: 0,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection(),
            recurringResults: new RecurringResultCollection(),
            errors: $errors,
        );
    }

    private function createErrorResult(): BatchResultRecord
    {
        $errors = new TaskErrorCollection();
        $errors->add(new TaskErrorRecord('task-1', 'Something went wrong'));

        return new BatchResultRecord(
            startedAt: new Iso8601DateTime(),
            uniqueSuccess: 0,
            uniqueFailed: 1,
            recurringSuccess: 0,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection(),
            recurringResults: new RecurringResultCollection(),
            errors: $errors,
        );
    }

    private function createFullSuccessResult(): BatchResultRecord
    {
        return new BatchResultRecord(
            startedAt: new Iso8601DateTime(),
            uniqueSuccess: 1,
            uniqueFailed: 0,
            recurringSuccess: 1,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection(),
            recurringResults: new RecurringResultCollection(),
            errors: new TaskErrorCollection(),
        );
    }

    private function createFailureWithoutVerboseResult(): BatchResultRecord
    {
        $errors = new TaskErrorCollection();
        $errors->add(new TaskErrorRecord('task-1', 'Connection timeout'));

        return new BatchResultRecord(
            startedAt: new Iso8601DateTime(),
            uniqueSuccess: 0,
            uniqueFailed: 1,
            recurringSuccess: 0,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection(),
            recurringResults: new RecurringResultCollection(),
            errors: $errors,
        );
    }

    // ==================== Tests: Signature, Description & Aliases ====================

    public function test_get_signature_returns_correct_string(): void
    {
        $directive = $this->getDirective();
        $signature = $directive->getSignature();

        $this->assertStringContainsString('process-tasks', $signature);
        $this->assertStringContainsString('--unique-only', $signature);
        $this->assertStringContainsString('--recurring-only', $signature);
        $this->assertStringContainsString('--verbose', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $directive = $this->getDirective();
        $description = $directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('batch', $description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $directive = $this->getDirective();
        $aliases = $directive->getAliases();

        $this->assertTrue($aliases->contains('task:process'));
        $this->assertTrue($aliases->contains('tasks:process'));
        $this->assertSame(2, $aliases->count());
    }

    // ==================== Tests: Process Modes ====================

    public function test_execute_processes_all_tasks_by_default(): void
    {
        $result = $this->createSuccessUniqueResult();
        $this->batch->expects($this->once())->method('process')->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_unique_only_flag(): void
    {
        $result = $this->createSuccessUniqueResult();
        $this->batch->expects($this->once())->method('processUniqueOnly')->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, ['--unique-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_recurring_only_flag(): void
    {
        $result = $this->createSuccessRecurringResult();
        $this->batch->expects($this->once())->method('processRecurringOnly')->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, ['--recurring-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    // ==================== Tests: Exit Codes ====================

    public function test_execute_returns_failure_when_tasks_fail(): void
    {
        $result = $this->createFailureResult();
        $this->batch->expects($this->once())->method('process')->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
    }

    public function test_execute_returns_failure_when_some_tasks_succeed(): void
    {
        $result = $this->createMixedResult();
        $this->batch->expects($this->once())->method('process')->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
    }

    // ==================== Tests: Limit Handling ====================

    public function test_execute_with_limit_passes_limit_to_batch(): void
    {
        $result = $this->createSuccessUniqueResult();
        $this->batch->expects($this->once())->method('process')->with(5)->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=5']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_limit_and_unique_only_passes_limit(): void
    {
        $result = $this->createSuccessUniqueResult();
        $this->batch->expects($this->once())->method('processUniqueOnly')->with(3)->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, ['--unique-only', '--limit=3']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_limit_and_recurring_only_passes_limit(): void
    {
        $result = $this->createSuccessRecurringResult();
        $this->batch->expects($this->once())->method('processRecurringOnly')->with(3)->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, ['--recurring-only', '--limit=3']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_limit_zero_returns_invalid_argument(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=0']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Limit must be a positive integer', $response->output);
    }

    public function test_execute_with_limit_negative_returns_invalid_argument(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=-5']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Limit must be a positive integer', $response->output);
    }

    public function test_execute_with_non_numeric_limit_returns_invalid_argument(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=abc']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
    }

    // ==================== Tests: Validation ====================

    public function test_execute_with_both_flags_returns_invalid_argument(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--unique-only', '--recurring-only']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Cannot use both', $response->output);
    }

    // ==================== Tests: Output ====================

    public function test_execute_output_contains_batch_results(): void
    {
        $result = $this->createFullSuccessResult();
        $this->batch->expects($this->once())->method('process')->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertStringContainsString('Batch Results', $response->output);
        $this->assertStringContainsString('Unique tasks:', $response->output);
        $this->assertStringContainsString('Recurring tasks:', $response->output);
        $this->assertStringContainsString('Total:', $response->output);
    }

    public function test_execute_with_verbose_flag_shows_errors(): void
    {
        $result = $this->createErrorResult();
        $this->batch->expects($this->once())->method('process')->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, ['--verbose']);

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('Failed Tasks', $response->output);
        $this->assertStringContainsString('task-1', $response->output);
        $this->assertStringContainsString('Something went wrong', $response->output);
    }

    public function test_execute_output_without_verbose_does_not_show_errors(): void
    {
        $result = $this->createFailureWithoutVerboseResult();
        $this->batch->expects($this->once())->method('process')->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertStringContainsString('Batch Results', $response->output);
        $this->assertStringNotContainsString('Failed Tasks', $response->output);
        $this->assertStringNotContainsString('Connection timeout', $response->output);
    }

    public function test_execute_with_limit_and_verbose_shows_limit_message(): void
    {
        $result = $this->createSuccessUniqueResult();
        $this->batch->expects($this->once())->method('process')->with(10)->willReturn($result);

        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=10', '--verbose']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Limit: 10 tasks', $response->output);
    }
}
