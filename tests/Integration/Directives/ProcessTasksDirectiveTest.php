<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Structs\BatchResultStruct;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Ramsey\Uuid\Uuid;

final class ProcessTasksDirectiveTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private DirectiveTestingService $service;

    private UniqueTaskRepository $uniqueRepository;

    private RecurringTaskRepositoryInterface $recurringRepository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DirectiveTestingService($this->app);

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->uniqueRepository = new UniqueTaskRepository($this->debugRepository);
        $this->recurringRepository = $this->app->make(RecurringTaskRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $this->service->destroy();
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function createUniqueTask(
        string $alias,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400
    ): void {
        $scheduledAt = $scheduledAt ?? now()->subHours(2);
        $id = $id ?? (string) Uuid::uuid4();

        $record = new UniqueTaskRecord(
            id: new TaskIdVO($id),
            alias: new TaskSignatureVO($alias),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from(['test' => 'unique']),
            scheduled_at: new Iso8601DateTimeVO($scheduledAt->format('Y-m-d\TH:i:sP')),
            grace_period_seconds: $gracePeriodSeconds,
            status: $status,
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );

        $this->uniqueRepository->create($record);
    }

    private function createFailingUniqueTask(): void
    {
        $id = (string) Uuid::uuid4();
        $record = new UniqueTaskRecord(
            id: new TaskIdVO($id),
            alias: new TaskSignatureVO('failing-task'),
            fqcn: FailingTask::class,
            payload: StrictDataObject::from(['test' => 'failing']),
            scheduled_at: new Iso8601DateTimeVO(now()->subHours(2)->format('Y-m-d\TH:i:sP')),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
            attempts: new CounterVO(2),
            max_attempts: new CounterVO(3),
        );

        $this->uniqueRepository->create($record);
    }

    private function createRecurringTask(
        string $alias,
        RecurringTaskStatus $status = RecurringTaskStatus::PLAYING,
        ?\DateTimeInterface $startAt = null,
        ?\DateTimeInterface $lastRunAt = null
    ): void {
        $startAt = $startAt ?? now()->subHours(2);
        $lastRunAt = $lastRunAt ?? now()->subHours(2);

        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO($alias),
            description: 'Test recurring task',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO($startAt->format('Y-m-d\TH:i:sP')),
            end_at: new Iso8601DateTimeVO(now()->addDays(7)->format('Y-m-d\TH:i:sP')),
            max_attempts: new CounterVO(3),
        );

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $aliasVO = $service->register(
            TestRecurringTask::class,
            StrictDataObject::from(['test' => 'recurring']),
            $config
        );

        $task = $this->recurringRepository->findByAlias($aliasVO->value);
        $task->status = $status;
        $task->last_run_at = $lastRunAt;
        $task->save();
    }

    private function createFailingRecurringTask(): void
    {
        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('failing-recurring'),
            description: 'Failing recurring task',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->subHours(2)->format('Y-m-d\TH:i:sP')),
            end_at: new Iso8601DateTimeVO(now()->addDays(7)->format('Y-m-d\TH:i:sP')),
            max_attempts: new CounterVO(3),
        );

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $aliasVO = $service->register(
            FailingRecurringTask::class,
            StrictDataObject::from(['should_fail' => true]),
            $config
        );

        $task = $this->recurringRepository->findByAlias($aliasVO->value);
        $task->status = RecurringTaskStatus::PLAYING;
        $task->last_run_at = now()->subHours(2);
        $task->save();
    }

    // ==================== TESTS: Signature ====================

    public function test_get_signature_returns_correct_string(): void
    {
        $directive = $this->app->make(ProcessTasksDirective::class);
        $signature = $directive->getSignature();

        $this->assertStringContainsString('process-tasks', $signature);
        $this->assertStringContainsString('--unique-only', $signature);
        $this->assertStringContainsString('--recurring-only', $signature);
        $this->assertStringContainsString('--verbose', $signature);
        $this->assertStringContainsString('--limit=', $signature);
        $this->assertStringContainsString('--format=', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $directive = $this->app->make(ProcessTasksDirective::class);
        $description = $directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $directive = $this->app->make(ProcessTasksDirective::class);
        $aliases = $directive->getAliases();

        $this->assertTrue($aliases->contains('task-process'));
        $this->assertTrue($aliases->contains('tasks-process'));
        $this->assertSame(2, $aliases->count());
    }

    // ==================== TESTS: Basic Execution ====================

    public function test_execute_returns_success_when_no_tasks(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
    }

    public function test_execute_with_unique_only_flag(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(ProcessTasksDirective::class, ['--unique-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Unique Batch Results ===', $response->output);
        $this->assertStringContainsString('Success: 1', $response->output);
        $this->assertStringContainsString('Failed: 0', $response->output);
        $this->assertStringContainsString('Total: 1', $response->output);
    }

    public function test_execute_with_recurring_only_flag(): void
    {
        $this->createRecurringTask('recurring-1');

        $response = $this->service->run(ProcessTasksDirective::class, ['--recurring-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Recurring Batch Results ===', $response->output);
        $this->assertStringContainsString('Success: 1', $response->output);
        $this->assertStringContainsString('Failed: 0', $response->output);
        $this->assertStringContainsString('Total: 1', $response->output);
    }

    public function test_execute_with_both_flags_returns_invalid_argument(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--unique-only', '--recurring-only']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Cannot use both', $response->output);
    }

    // ==================== TESTS: Limit ====================

    public function test_execute_with_limit_passes_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=3', '--unique-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Limit: 3 tasks', $response->output);
        $this->assertStringContainsString('=== Unique Batch Results ===', $response->output);
        $this->assertStringContainsString('Success: 3', $response->output);
        $this->assertStringContainsString('Failed: 0', $response->output);
        $this->assertStringContainsString('Total: 3', $response->output);
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

    // ==================== TESTS: JSON Output ====================

    public function test_json_output_returns_valid_json(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--format=json']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $data = json_decode($response->output, true);
        $this->assertNotNull($data, 'Output should be valid JSON');

        // ✅ Nouvelle structure plate
        $this->assertArrayHasKey('started_at', $data);
        $this->assertArrayHasKey('ended_at', $data);
        $this->assertArrayHasKey('duration_ms', $data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('failed', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('has_failures', $data);

        // ✅ Vérifier les valeurs au niveau racine
        $this->assertEquals(3, $data['success']);  // 2 unique + 1 recurring
        $this->assertEquals(0, $data['failed']);
        $this->assertEquals(3, $data['total']);

        // ✅ Vérifier que les erreurs sont vides
        $this->assertIsArray($data['errors']);
        $this->assertCount(0, $data['errors']);

        $this->assertFalse($data['has_failures']);
    }

    public function test_json_output_with_unique_only(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--unique-only', '--format=json']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $data = json_decode($response->output, true);
        $this->assertNotNull($data);

        $this->assertArrayHasKey('started_at', $data);
        $this->assertArrayHasKey('ended_at', $data);
        $this->assertArrayHasKey('duration_ms', $data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('failed', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('has_failures', $data);

        $this->assertEquals(2, $data['success']);
        $this->assertEquals(0, $data['failed']);
        $this->assertEquals(2, $data['total']);
        $this->assertFalse($data['has_failures']);
    }

    public function test_json_output_with_recurring_only(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createRecurringTask('recurring-1');
        $this->createRecurringTask('recurring-2');

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--recurring-only', '--format=json']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $data = json_decode($response->output, true);
        $this->assertNotNull($data);

        $this->assertArrayHasKey('started_at', $data);
        $this->assertArrayHasKey('ended_at', $data);
        $this->assertArrayHasKey('duration_ms', $data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('failed', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('has_failures', $data);

        $this->assertEquals(2, $data['success']);
        $this->assertEquals(0, $data['failed']);
        $this->assertEquals(2, $data['total']);
        $this->assertFalse($data['has_failures']);
    }

    public function test_json_output_with_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--limit=3', '--unique-only', '--format=json']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $data = json_decode($response->output, true);
        $this->assertNotNull($data);

        $this->assertEquals(3, $data['total']);
        $this->assertEquals(3, $data['success']);
        $this->assertEquals(0, $data['failed']);
    }

    public function test_json_output_with_errors(): void
    {
        $this->createFailingUniqueTask();

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--format=json']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);

        $data = json_decode($response->output, true);
        $this->assertNotNull($data);

        // ✅ Nouvelle structure plate
        $this->assertArrayHasKey('started_at', $data);
        $this->assertArrayHasKey('ended_at', $data);
        $this->assertArrayHasKey('duration_ms', $data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('failed', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('has_failures', $data);

        // ✅ Vérifier les valeurs au niveau racine
        $this->assertEquals(0, $data['success']);
        $this->assertEquals(1, $data['failed']);
        $this->assertEquals(1, $data['total']);

        // ✅ Vérifier les erreurs au niveau racine
        $this->assertIsArray($data['errors']);
        $this->assertGreaterThan(0, count($data['errors']));

        if (! empty($data['errors'])) {
            $error = $data['errors'][0];
            $this->assertArrayHasKey('alias', $error);
            $this->assertArrayHasKey('fqcn', $error);
            $this->assertArrayHasKey('error', $error);
            $this->assertArrayHasKey('context', $error);
            $this->assertEquals('failing-task', $error['alias']);
            $this->assertEquals('Task execution failed', $error['error']);
        }

        $this->assertTrue($data['has_failures']);
    }

    public function test_json_output_with_recurring_errors(): void
    {
        $this->createFailingRecurringTask();

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--format=json']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);

        $data = json_decode($response->output, true);
        $this->assertNotNull($data);

        // ✅ Nouvelle structure plate
        $this->assertArrayHasKey('started_at', $data);
        $this->assertArrayHasKey('ended_at', $data);
        $this->assertArrayHasKey('duration_ms', $data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('failed', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('has_failures', $data);

        // ✅ Vérifier les valeurs au niveau racine (plus de 'recurring')
        $this->assertEquals(0, $data['success']);
        $this->assertEquals(1, $data['failed']);
        $this->assertEquals(1, $data['total']);

        // ✅ Vérifier les erreurs au niveau racine
        $this->assertIsArray($data['errors']);
        $this->assertGreaterThan(0, count($data['errors']));

        if (! empty($data['errors'])) {
            $error = $data['errors'][0];
            $this->assertArrayHasKey('alias', $error);
            $this->assertArrayHasKey('fqcn', $error);
            $this->assertArrayHasKey('error', $error);
            $this->assertArrayHasKey('context', $error);
            $this->assertEquals('failing-recurring', $error['alias']);
            $this->assertEquals('Recurring task failed', $error['context']);
        }

        $this->assertTrue($data['has_failures']);
    }

    public function test_json_output_hydrates_to_struct(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--format=json']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $struct = BatchResultStruct::fromJson($response->output);

        // ✅ La nouvelle structure est plate
        $this->assertEquals(2, $struct->success);
        $this->assertEquals(0, $struct->failed);
        $this->assertEquals(2, $struct->total);
        $this->assertEquals(0, $struct->errors->count());
        $this->assertFalse($struct->has_failures);
    }

    public function test_invalid_format_returns_error(): void
    {
        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--format=xml']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Format must be "text" or "json"', $response->output);
    }

    // ==================== TESTS: Verbose ====================

    public function test_verbose_output_shows_errors(): void
    {
        $this->createFailingUniqueTask();

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--verbose']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('=== Failed Tasks ===', $response->output);
        $this->assertStringContainsString('failing-task', $response->output);
        $this->assertStringContainsString('Task execution failed', $response->output);
    }

    public function test_verbose_output_without_errors(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--verbose']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringNotContainsString('=== Failed Tasks ===', $response->output);
        $this->assertStringNotContainsString('=== Failed Unique Tasks ===', $response->output);
    }

    public function test_verbose_output_for_full_mode_shows_both_errors(): void
    {
        $this->createFailingUniqueTask();
        $this->createFailingRecurringTask();

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--verbose']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('=== Failed Tasks ===', $response->output);
        $this->assertStringContainsString('Unique tasks:', $response->output);
        $this->assertStringContainsString('Recurring tasks:', $response->output);
        $this->assertStringContainsString('failing-task', $response->output);
        $this->assertStringContainsString('failing-recurring', $response->output);
    }

    // ==================== TESTS: Output Format ====================

    public function test_text_output_contains_batch_results(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('Unique:', $response->output);
        $this->assertStringContainsString('Recurring:', $response->output);
        $this->assertStringContainsString('Total:', $response->output);
    }

    public function test_text_output_shows_limit_message(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--limit=3', '--unique-only']
        );

        $this->assertStringContainsString('Limit: 3 tasks', $response->output);
        $this->assertStringContainsString('=== Unique Batch Results ===', $response->output);
        $this->assertStringContainsString('Success: 3', $response->output);
    }

    public function test_full_mode_text_output_shows_combined_results(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('Unique:    ✅ 2, ❌ 0', $response->output);
        $this->assertStringContainsString('Recurring: ✅ 1, ❌ 0', $response->output);
        $this->assertStringContainsString('Total:     ✅ 3, ❌ 0, 📦 3', $response->output);
        $this->assertStringContainsString('Has failures: No', $response->output);
    }

    public function test_full_mode_with_errors_shows_failures(): void
    {
        $this->createFailingUniqueTask();
        $this->createRecurringTask('recurring-1');

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('Unique:    ✅ 0, ❌ 1', $response->output);
        $this->assertStringContainsString('Recurring: ✅ 1, ❌ 0', $response->output);
        $this->assertStringContainsString('Total:     ✅ 1, ❌ 1, 📦 2', $response->output);
        $this->assertStringContainsString('Has failures: Yes', $response->output);
    }
}
