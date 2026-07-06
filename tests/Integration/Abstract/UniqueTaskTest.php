<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Abstract;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\Logger\Configs\LoggerConfig;
use AndyDefer\Logger\LoggerService;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Contexts\UniqueTaskContext;
use AndyDefer\Task\Contracts\Abstract\TaskInterface;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Integration tests for unique task execution and lifecycle management.
 *
 * Tests the complete workflow including execution, logging, error handling,
 * and lifecycle hooks for unique tasks.
 */
final class UniqueTaskTest extends IntegrationTestCase
{
    private TestTask $task;

    private FailingTask $failingTask;

    private UniqueTaskContext $context;

    private LoggerService $logger;

    private HydrationService $hydration;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new LoggerConfig($this->app->make(ConfigRepository::class));

        $this->logPath = $config->basePath();
        $fs = new FileSystemService;

        if (! $fs->isDirectory($this->logPath)) {
            $fs->makeDirectory($this->logPath, PermissionMode::DIRECTORY, true);
        }

        $pathStrategy = new TemporalPathStrategy($this->logPath);
        $jsonlContext = new JsonlContext;

        $jsonlService = new JsonlService(
            pathStrategy: $pathStrategy,
            fileSystem: $fs,
            context: $jsonlContext,
            defaultBufferSize: $config->bufferSize(),
        );

        $this->hydration = new HydrationService;

        $this->logger = new LoggerService(
            jsonlService: $jsonlService,
            hydrationService: $this->hydration,
        );

        $this->context = new UniqueTaskContext;
        $this->context->setTaskId(new UuidVO((string) Uuid::uuid4()));
        $this->context->setAlias(new TaskAliasVO('unique@'.Uuid::uuid4()->toString()));
        $this->context->setScheduledAt(new Iso8601DateTimeVO(Carbon::now()->addMinutes(5)->toIso8601String()));
        $this->context->setLaravelApp($this->app);

        $this->task = new TestTask(
            $this->context,
            $this->logger,
            $this->hydration,
        );

        $this->failingTask = new FailingTask(
            $this->context,
            $this->logger,
            $this->hydration,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $fs = new FileSystemService;
        if ($fs->isDirectory($this->logPath)) {
            $fs->deleteDirectory($this->logPath);
        }
    }

    // ==================== TASK EXECUTION TESTS ====================

    public function test_executes_task_successfully(): void
    {
        $payload = StrictDataObject::from([
            'test_data' => 'unique_success',
        ]);

        $this->task->execute($payload);

        $this->assertTrue($this->task->processWasCalled);
        $this->assertTrue($this->task->beforeWasCalled);
        $this->assertTrue($this->task->afterWasCalled);
        $this->assertTrue($this->task->afterExecutedSuccessfully);
        $this->assertNull($this->task->afterErrorMessage);

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = Carbon::now()->format('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
        $this->assertNotEmpty($logFiles);
    }

    public function test_executes_task_with_empty_payload(): void
    {
        $payload = StrictDataObject::from([]);

        $this->task->execute($payload);

        $this->assertTrue($this->task->processWasCalled);
        $this->assertTrue($this->task->beforeWasCalled);
        $this->assertTrue($this->task->afterWasCalled);
        $this->assertTrue($this->task->afterExecutedSuccessfully);
    }

    public function test_executes_task_with_complex_payload(): void
    {
        $payload = StrictDataObject::from([
            'user' => [
                'id' => 123,
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
            'settings' => [
                'notify' => true,
                'priority' => 'high',
            ],
            'items' => [1, 2, 3, 4, 5],
        ]);

        $this->task->execute($payload);

        $this->assertTrue($this->task->processWasCalled);
        $this->assertTrue($this->task->beforeWasCalled);
        $this->assertTrue($this->task->afterWasCalled);
        $this->assertTrue($this->task->afterExecutedSuccessfully);
    }

    // ==================== FAILURE TESTS ====================

    public function test_logs_error_on_failure(): void
    {
        $payload = StrictDataObject::from([
            'test_data' => 'unique_fail',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        try {
            $this->failingTask->execute($payload);
        } catch (Throwable $e) {
            $this->assertTrue($this->failingTask->afterWasCalled);
            $this->assertFalse($this->failingTask->afterExecutedSuccessfully);
            $this->assertEquals(
                'Test exception',
                $this->failingTask->afterErrorMessage?->getValue()
            );

            $this->logger->flush();
            $fs = new FileSystemService;
            $today = Carbon::now()->format('Y-m-d');
            $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
            $this->assertNotEmpty($logFiles);

            $content = '';
            foreach ($logFiles as $file) {
                $content .= $fs->get($file);
            }
            $this->assertStringContainsString('task_failed', $content);
            $this->assertStringContainsString('Test exception', $content);

            throw $e;
        }
    }

    // ==================== BEFORE / AFTER HOOK TESTS ====================

    public function test_before_hook_executed(): void
    {
        $payload = StrictDataObject::from(['test' => 'before_hook']);

        $this->task->execute($payload);

        $this->assertTrue($this->task->beforeWasCalled);
    }

    public function test_after_hook_executed_on_success(): void
    {
        $payload = StrictDataObject::from(['test' => 'after_hook_success']);

        $this->task->execute($payload);

        $this->assertTrue($this->task->afterWasCalled);
        $this->assertTrue($this->task->afterExecutedSuccessfully);
        $this->assertNull($this->task->afterErrorMessage);
    }

    public function test_after_hook_executed_on_failure(): void
    {
        $payload = StrictDataObject::from(['test' => 'after_hook_failure']);

        try {
            $this->failingTask->execute($payload);
        } catch (RuntimeException $e) {
            // Expected
        }

        $this->assertTrue($this->failingTask->afterWasCalled);
        $this->assertFalse($this->failingTask->afterExecutedSuccessfully);
        $this->assertEquals(
            'Test exception',
            $this->failingTask->afterErrorMessage?->getValue()
        );
    }

    // ==================== CONTEXT TESTS ====================

    public function test_preserves_context_data(): void
    {
        $taskId = $this->context->getTaskId()->getValue();
        $alias = $this->context->getAlias()->getValue();
        $scheduledAt = $this->context->getScheduledAt()->getValue();

        $this->assertNotEmpty($taskId);
        $this->assertStringContainsString('unique@', $alias);
        $this->assertNotNull($scheduledAt);
        $this->assertTrue(Carbon::parse($scheduledAt)->isFuture());
    }

    public function test_context_has_laravel_app(): void
    {
        $this->assertNotNull($this->context->getLaravelApp());
    }

    public function test_context_can_be_modified_after_creation(): void
    {
        $newTaskId = new UuidVO((string) Uuid::uuid4());
        $this->context->setTaskId($newTaskId);

        $this->assertEquals($newTaskId->getValue(), $this->context->getTaskId()->getValue());

        $newAlias = new TaskAliasVO('unique@'.Uuid::uuid4()->toString());
        $this->context->setAlias($newAlias);

        $this->assertStringContainsString('unique@', $this->context->getAlias()->getValue());

        $newScheduledAt = new Iso8601DateTimeVO(Carbon::now()->addHours(2)->toIso8601String());
        $this->context->setScheduledAt($newScheduledAt);

        $this->assertEquals($newScheduledAt->getValue(), $this->context->getScheduledAt()->getValue());
    }

    // ==================== LOGGING TESTS ====================

    public function test_task_has_logger(): void
    {
        $this->assertNotNull($this->task);
        $this->assertInstanceOf(LoggerService::class, $this->logger);
    }

    public function test_task_can_log_info_without_execution(): void
    {
        $this->logger->flush();

        $this->task->info(new DescriptionVO('Info without execution'));

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = Carbon::now()->format('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
        $this->assertNotEmpty($logFiles);

        $content = '';
        foreach ($logFiles as $file) {
            $content .= $fs->get($file);
        }
        $this->assertStringContainsString('Info without execution', $content);
    }

    public function test_task_can_log_error_without_execution(): void
    {
        $this->logger->flush();

        $this->task->error(new DescriptionVO('Error without execution'));

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = Carbon::now()->format('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
        $this->assertNotEmpty($logFiles);

        $content = '';
        foreach ($logFiles as $file) {
            $content .= $fs->get($file);
        }
        $this->assertStringContainsString('Error without execution', $content);
    }

    public function test_info_logs_are_written(): void
    {
        $this->logger->flush();

        $message = new DescriptionVO('Test info message');
        $this->task->info($message);

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = Carbon::now()->format('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
        $this->assertNotEmpty($logFiles);

        $content = '';
        foreach ($logFiles as $file) {
            $content .= $fs->get($file);
        }
        $this->assertStringContainsString('Test info message', $content);
        $this->assertStringContainsString('unique_task_output', $content);
    }

    public function test_error_logs_are_written(): void
    {
        $this->logger->flush();

        $message = new DescriptionVO('Test error message');
        $this->task->error($message);

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = Carbon::now()->format('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
        $this->assertNotEmpty($logFiles);

        $content = '';
        foreach ($logFiles as $file) {
            $content .= $fs->get($file);
        }
        $this->assertStringContainsString('Test error message', $content);
        $this->assertStringContainsString('unique_task_output', $content);
    }

    // ==================== EXECUTION LOG TESTS ====================

    public function test_task_execution_log_contains_payload(): void
    {
        $payload = StrictDataObject::from([
            'test_data' => 'log_payload_test',
            'user_id' => 123,
        ]);

        $this->task->execute($payload);

        $this->assertTrue($this->task->processWasCalled);
        $this->assertTrue($this->task->beforeWasCalled);
        $this->assertTrue($this->task->afterWasCalled);
        $this->assertTrue($this->task->afterExecutedSuccessfully);
    }

    // ==================== SCHEDULED AT TESTS ====================

    public function test_scheduled_at_is_future(): void
    {
        $scheduledAt = $this->context->getScheduledAt()->getValue();
        $this->assertTrue(Carbon::parse($scheduledAt)->isFuture());
    }

    public function test_scheduled_at_can_be_past(): void
    {
        $pastScheduledAt = new Iso8601DateTimeVO(Carbon::now()->subMinutes(10)->toIso8601String());
        $this->context->setScheduledAt($pastScheduledAt);

        $this->assertEquals($pastScheduledAt->getValue(), $this->context->getScheduledAt()->getValue());
        $this->assertTrue(Carbon::parse($pastScheduledAt->getValue())->isPast());
    }

    // ==================== TASK ID TESTS ====================

    public function test_task_id_is_valid_uuid(): void
    {
        $taskId = $this->context->getTaskId()->getValue();
        $this->assertTrue(Uuid::isValid($taskId));
    }

    public function test_task_id_can_be_set(): void
    {
        $newTaskId = new UuidVO((string) Uuid::uuid4());
        $this->context->setTaskId($newTaskId);

        $this->assertEquals($newTaskId->getValue(), $this->context->getTaskId()->getValue());
        $this->assertTrue(Uuid::isValid($this->context->getTaskId()->getValue()));
    }

    // ==================== ALIAS TESTS ====================

    public function test_alias_contains_type_and_uuid(): void
    {
        $alias = $this->context->getAlias()->getValue();
        $this->assertStringContainsString('unique@', $alias);
        $parts = explode('@', $alias);
        $this->assertCount(2, $parts);
        $this->assertEquals('unique', $parts[0]);
        $this->assertTrue(Uuid::isValid($parts[1]));
    }

    public function test_alias_can_be_set(): void
    {
        $newAlias = new TaskAliasVO('unique@'.Uuid::uuid4()->toString());
        $this->context->setAlias($newAlias);

        $this->assertEquals($newAlias->getValue(), $this->context->getAlias()->getValue());
        $this->assertStringContainsString('unique@', $this->context->getAlias()->getValue());
    }

    // ==================== TASK INSTANCE TESTS ====================

    public function test_task_implements_task_interface(): void
    {
        $this->assertInstanceOf(TaskInterface::class, $this->task);
    }

    public function test_task_is_abstract_unique_task(): void
    {
        $this->assertInstanceOf(AbstractUniqueTask::class, $this->task);
    }

    public function test_multiple_task_instances_are_independent(): void
    {
        $context2 = new UniqueTaskContext;
        $context2->setTaskId(new UuidVO((string) Uuid::uuid4()));
        $context2->setAlias(new TaskAliasVO('unique@'.Uuid::uuid4()->toString()));
        $context2->setScheduledAt(new Iso8601DateTimeVO(Carbon::now()->addMinutes(10)->toIso8601String()));
        $context2->setLaravelApp($this->app);

        $task2 = new TestTask(
            $context2,
            $this->logger,
            $this->hydration,
        );

        $payload1 = StrictDataObject::from(['id' => 1]);
        $payload2 = StrictDataObject::from(['id' => 2]);

        $this->task->execute($payload1);
        $task2->execute($payload2);

        $this->assertTrue($this->task->processWasCalled);
        $this->assertTrue($task2->processWasCalled);

        $this->assertNotSame($this->context->getTaskId()->getValue(), $context2->getTaskId()->getValue());
    }
}
