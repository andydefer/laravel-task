<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
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

        $frozenNow = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($frozenNow);

        $this->service = new DirectiveTestingService(
            $this->app,
        );

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->uniqueRepository = new UniqueTaskRepository(
            $this->debugRepository,
            $this->app->make(LoggerInterface::class)
        );
        $this->recurringRepository = $this->app->make(RecurringTaskRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        $this->service->destroy();
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function getUuidForAlias(string $aliasName): string
    {
        $uuid = Uuid::uuid4()->toString();

        return $uuid;
    }

    private function generateAliasFromName(string $name, ?string $uuid = null): TaskAliasVO
    {
        $uuid = $uuid ?? $this->getUuidForAlias($name);
        $alias = new TaskAliasVO('unique@'.$uuid);

        return $alias;
    }

    private function createUniqueTask(
        string $alias,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3
    ): void {
        $scheduledAt = $scheduledAt ?? Carbon::now()->subHours(2);
        $id = $id ?? $this->getUuidForAlias($alias);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $this->generateAliasFromName($alias, $id),
            'fqcn' => new UniqueTaskFqcnVO(TestUniqueTask::class),
            'payload' => StrictDataObject::from(['test' => 'unique']),
            'scheduled_at' => new Iso8601DateTimeVO($scheduledAt->format('Y-m-d\TH:i:sP')),
            'grace_period_seconds' => new DurationVO($gracePeriodSeconds),
            'status' => $status,
            'attempts' => new CounterVO($attempts),
            'max_attempts' => new MaxFailedAttemptsVO($maxAttempts),
        ]);

        $this->uniqueRepository->create($record);
    }

    private function createFailingUniqueTask(): void
    {
        $alias = 'failing-task';
        $id = $this->getUuidForAlias($alias);

        $record = UniqueTaskRecord::from([
            'id' => $id,
            'alias' => $this->generateAliasFromName($alias, $id),
            'fqcn' => FailingTask::class,
            'payload' => ['test' => 'failing'],
            'scheduled_at' => Carbon::now()->subHours(2)->format('Y-m-d\TH:i:sP'),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING->value,
            'attempts' => 2,
            'max_attempts' => 3,
        ]);

        $this->uniqueRepository->create($record);
    }

    private function createRecurringTask(
        string $alias,
        RecurringTaskStatus $status = RecurringTaskStatus::PLAYING,
        ?\DateTimeInterface $startAt = null,
        ?\DateTimeInterface $lastRunAt = null,
        int $intervalSeconds = 3600
    ): void {
        $startAt = $startAt ?? Carbon::now()->subHours(2);
        $lastRunAt = $lastRunAt ?? null;
        $id = $this->getUuidForAlias($alias);

        $config = RecurringTaskConfigRecord::from([
            'type' => TaskType::RECURRING->value,
            'description' => 'Test recurring task',
            'interval_seconds' => $intervalSeconds,
            'start_at' => $startAt->format('Y-m-d\TH:i:sP'),
            'end_at' => Carbon::now()->addDays(7)->format('Y-m-d\TH:i:sP'),
            'max_attempts' => 3,
        ]);

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $payload = StrictDataObject::from(['test' => 'recurring']);

        $aliasVO = $service->register($fqcn, $payload, $config);

        $task = $this->recurringRepository->findByAlias($aliasVO);
        if ($task) {
            $this->recurringRepository->updateRaw(
                $task->getId()->getValue(),
                [
                    'status' => $status->value,
                    'start_at' => $startAt->format('Y-m-d H:i:s'),
                    'last_run_at' => $lastRunAt ? $lastRunAt->format('Y-m-d H:i:s') : null,
                ]
            );
        }
    }

    private function createFailingRecurringTask(): void
    {
        $alias = 'failing-recurring';

        $config = RecurringTaskConfigRecord::from([
            'type' => TaskType::RECURRING->value,
            'description' => 'Failing recurring task',
            'interval_seconds' => 3000,
            'start_at' => Carbon::now()->subHours(2)->format('Y-m-d\TH:i:sP'),
            'end_at' => Carbon::now()->addDays(7)->format('Y-m-d\TH:i:sP'),
            'max_attempts' => 3,
        ]);

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $fqcn = new RecurringTaskFqcnVO(FailingRecurringTask::class);
        $payload = StrictDataObject::from(['should_fail' => true]);

        $aliasVO = $service->register($fqcn, $payload, $config);

        $task = $this->recurringRepository->findByAlias($aliasVO);
        if ($task) {
            $this->recurringRepository->updateRaw(
                $task->getId()->getValue(),
                [
                    'status' => RecurringTaskStatus::PLAYING->value,
                    'start_at' => Carbon::now()->subHours(2)->format('Y-m-d H:i:s'),
                    'last_run_at' => null,
                ]
            );
        }
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
        $this->assertStringContainsString('limit=infinite', $signature);
        $this->assertStringContainsString('format=text', $signature);
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
        $response = $this->service->runDirective(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('Unique:    ✅ 0, ❌ 0', $response->output);
        $this->assertStringContainsString('Recurring: ✅ 0, ❌ 0', $response->output);
        $this->assertStringContainsString('Total:     ✅ 0, ❌ 0, 📦 0', $response->output);
        $this->assertStringContainsString('Has failures: No', $response->output);
    }

    public function test_execute_with_unique_only_flag(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->runDirective(ProcessTasksDirective::class, ['--unique-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Unique Batch Results ===', $response->output);
        $this->assertStringContainsString('Success: 1', $response->output);
        $this->assertStringContainsString('Failed: 0', $response->output);
        $this->assertStringContainsString('Total: 1', $response->output);
    }

    public function test_execute_with_recurring_only_flag(): void
    {
        $this->createRecurringTask('recurring-1');

        $response = $this->service->runDirective(ProcessTasksDirective::class, ['--recurring-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Recurring Batch Results ===', $response->output);
        $this->assertStringContainsString('Success: 1', $response->output);
        $this->assertStringContainsString('Failed: 0', $response->output);
        $this->assertStringContainsString('Total: 1', $response->output);
    }

    public function test_execute_with_both_flags_returns_invalid_argument(): void
    {
        $response = $this->service->runDirective(ProcessTasksDirective::class, ['--unique-only', '--recurring-only']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Cannot use both --unique-only and --recurring-only', $response->output);
    }

    // ==================== TESTS: Limit ====================

    public function test_execute_with_limit_passes_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->runDirective(ProcessTasksDirective::class, ['3', '--unique-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Limit: 3 tasks', $response->output);
        $this->assertStringContainsString('=== Unique Batch Results ===', $response->output);
        $this->assertStringContainsString('Success: 3', $response->output);
        $this->assertStringContainsString('Failed: 0', $response->output);
        $this->assertStringContainsString('Total: 3', $response->output);
    }

    public function test_execute_with_limit_zero_returns_invalid_argument(): void
    {

        // ✅ 0 = aucune limite, donc SUCCESS
        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['0']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Limit: infinite (no limit)', $response->output);
    }

    // ==================== TESTS: JSON Output ====================

    public function test_json_output_returns_valid_json(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['~', 'json']  // limit non spécifié (~), format=json
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $cleaned = $this->stripAnsi($response->output);
        $data = json_decode($cleaned, true);

        $this->assertNotNull($data);
        $this->assertArrayHasKey('started_at', $data);
        $this->assertArrayHasKey('ended_at', $data);
        $this->assertArrayHasKey('duration_ms', $data);
        $this->assertArrayHasKey('total_success', $data);
        $this->assertArrayHasKey('total_failed', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('has_failures', $data);
        $this->assertArrayHasKey('unique', $data);
        $this->assertArrayHasKey('recurring', $data);

        $this->assertEquals(3, $data['total_success']);
        $this->assertEquals(0, $data['total_failed']);
        $this->assertEquals(3, $data['total']);
        $this->assertIsArray($data['errors']);
        $this->assertCount(0, $data['errors']);
        $this->assertFalse($data['has_failures']);
    }

    public function test_json_output_with_unique_only(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['~', 'json', '--unique-only']  // limit non spécifié, format=json, flag --unique-only
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $cleaned = $this->stripAnsi($response->output);
        $data = json_decode($cleaned, true);

        $this->assertNotNull($data);
        $this->assertArrayHasKey('type', $data);
        $this->assertEquals('unique', $data['type']);
        $this->assertEquals(2, $data['success']);
        $this->assertEquals(0, $data['failed']);
        $this->assertEquals(2, $data['total']);
    }

    public function test_json_output_with_recurring_only(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createRecurringTask('recurring-1');
        $this->createRecurringTask('recurring-2');

        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['~', 'json', '--recurring-only']  // limit non spécifié, format=json, flag --recurring-only
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $cleaned = $this->stripAnsi($response->output);
        $data = json_decode($cleaned, true);

        $this->assertNotNull($data);
        $this->assertArrayHasKey('type', $data);
        $this->assertEquals('recurring', $data['type']);
        $this->assertEquals(2, $data['success']);
        $this->assertEquals(0, $data['failed']);
        $this->assertEquals(2, $data['total']);
    }

    public function test_json_output_with_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['3', 'json', '--unique-only']  // limit=3, format=json, flag --unique-only
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);

        $cleaned = $this->stripAnsi($response->output);
        $data = json_decode($cleaned, true);

        $this->assertNotNull($data);
        $this->assertEquals(3, $data['total']);
        $this->assertEquals(3, $data['success']);
        $this->assertEquals(0, $data['failed']);
    }

    public function test_json_output_with_errors(): void
    {
        $this->createFailingUniqueTask();

        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['~', 'json']  // limit non spécifié, format=json
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);

        $cleaned = $this->stripAnsi($response->output);
        $data = json_decode($cleaned, true);

        $this->assertNotNull($data);
        $this->assertEquals(0, $data['total_success']);
        $this->assertEquals(1, $data['total_failed']);
        $this->assertIsArray($data['errors']);
        $this->assertGreaterThan(0, count($data['errors']));
        $this->assertTrue($data['has_failures']);
    }

    public function test_json_output_with_recurring_errors(): void
    {
        $this->createFailingRecurringTask();

        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['~', 'json']  // limit non spécifié, format=json
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);

        $cleaned = $this->stripAnsi($response->output);
        $data = json_decode($cleaned, true);

        $this->assertNotNull($data);
        $this->assertEquals(0, $data['total_success']);
        $this->assertEquals(1, $data['total_failed']);
        $this->assertIsArray($data['errors']);
        $this->assertGreaterThan(0, count($data['errors']));
        $this->assertTrue($data['has_failures']);
    }

    public function test_invalid_format_returns_error(): void
    {
        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['~', 'xml']  // limit non spécifié, format=xml (invalide)
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Format must be "text" or "json"', $response->output);
    }

    // ==================== TESTS: Verbose ====================

    public function test_verbose_output_shows_errors(): void
    {
        $this->createFailingUniqueTask();

        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['--verbose']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('Test exception', $response->output);
        $this->assertStringContainsString('=== Failed Tasks ===', $response->output);
    }

    public function test_verbose_output_without_errors(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['--verbose']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringNotContainsString('=== Failed Tasks ===', $response->output);
    }

    public function test_verbose_output_for_full_mode_shows_both_errors(): void
    {
        $this->createFailingUniqueTask();
        $this->createFailingRecurringTask();

        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['--verbose']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('=== Failed Tasks ===', $response->output);
        $this->assertStringContainsString('Unique tasks:', $response->output);
        $this->assertStringContainsString('Recurring tasks:', $response->output);
        $this->assertStringContainsString('Test exception', $response->output);
        $this->assertStringContainsString('Task failed', $response->output);
    }

    // ==================== TESTS: Output Format ====================

    public function test_text_output_contains_batch_results(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->runDirective(ProcessTasksDirective::class, []);

        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('Unique:    ✅ 1, ❌ 0', $response->output);
        $this->assertStringContainsString('Recurring: ✅ 0, ❌ 0', $response->output);
        $this->assertStringContainsString('Total:     ✅ 1, ❌ 0, 📦 1', $response->output);
        $this->assertStringContainsString('Has failures: No', $response->output);
    }

    public function test_text_output_shows_limit_message(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->runDirective(
            ProcessTasksDirective::class,
            ['3', '--unique-only']  // limit=3, flag --unique-only
        );

        $this->assertStringContainsString('Limit: 3 tasks', $response->output);
        $this->assertStringContainsString('=== Unique Batch Results ===', $response->output);
        $this->assertStringContainsString('Success: 3', $response->output);
        $this->assertStringContainsString('Failed: 0', $response->output);
        $this->assertStringContainsString('Total: 3', $response->output);
    }

    public function test_full_mode_text_output_shows_combined_results(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->runDirective(ProcessTasksDirective::class, []);

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

        $response = $this->service->runDirective(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('Unique:    ✅ 0, ❌ 1', $response->output);
        $this->assertStringContainsString('Recurring: ✅ 1, ❌ 0', $response->output);
        $this->assertStringContainsString('Total:     ✅ 1, ❌ 1, 📦 2', $response->output);
        $this->assertStringContainsString('Has failures: Yes', $response->output);
    }
}
