<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contexts\TaskStorageContext;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskRepository;
use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Strategies\TaskPathStrategy;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Carbon\Carbon;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class TaskBatchServiceTest extends IntegrationTestCase
{
    private TaskRepositoryInterface $taskRepository;
    private RecurringTaskRepositoryInterface $recurringTaskRepository;
    private TaskBatchService $batch;
    private string $storagePath;
    private LoggerInterface $logger;
    private TaskConfigInterface $config;
    private ConfigRepository $configRepository;
    private HydrationService $hydration;
    private FileSystemInterface $fs;

    private function generateUuid(int $number): string
    {
        return sprintf('550e8400-e29b-41d4-a716-44665544%04d', $number);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = sys_get_temp_dir() . '/task_storage_' . uniqid();
        $this->configRepository = $this->app->make(ConfigRepository::class);
        $this->hydration = new HydrationService();
        $this->fs = new FileSystemService();

        $this->setConfigDefaults();
    }

    private function setConfigDefaults(array $configOverrides = []): void
    {
        $defaults = [
            'task.storage_path' => $this->storagePath,
            'task.grace_period.enabled' => false,
            'task.grace_period.seconds' => 86400,
            'task.batch.limit' => 1000,
            'task.batch.order' => 'oldest',
        ];

        $config = array_merge($defaults, $configOverrides);

        foreach ($config as $key => $value) {
            $this->configRepository->set($key, $value);
        }

        $this->config = new TaskConfig($this->configRepository);
    }

    private function createBatchService(): void
    {
        $context = new TaskStorageContext($this->config);
        $strategy = new TaskPathStrategy($this->config->storagePath());
        $jsonlContext = new JsonlContext();
        $jsonlService = new JsonlService(
            pathStrategy: $strategy,
            fileSystem: $this->fs,
            context: $jsonlContext,
        );

        $this->taskRepository = new TaskRepository(
            context: $context,
            jsonl: $jsonlService,
            hydration: $this->hydration,
            fs: $this->fs,
        );

        $this->recurringTaskRepository = new RecurringTaskRepository(
            context: $context,
            jsonl: $jsonlService,
            hydration: $this->hydration,
            fs: $this->fs,
        );

        $this->logger = $this->app->make(LoggerInterface::class);
        $validator = new TaskValidatorService(
            config: $this->config,
            hydration: $this->hydration,
            logger: $this->logger,
            app: $this->app,
        );

        $runner = new TaskRunnerService(
            taskRepository: $this->taskRepository,
            recurringTaskRepository: $this->recurringTaskRepository,
            logger: $this->logger,
            validator: $validator,
            config: $this->config,
            hydration: $this->hydration,
            fs: $this->fs,
            app: $this->app,
        );

        $batchResultService = new BatchResultService($this->hydration);

        $this->batch = new TaskBatchService(
            taskRepository: $this->taskRepository,
            recurringTaskRepository: $this->recurringTaskRepository,
            runner: $runner,
            validator: $validator,
            logger: $this->logger,
            batchResultService: $batchResultService,
            config: $this->config,
            hydration: $this->hydration,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (is_dir($this->storagePath)) {
            $this->removeDirectory($this->storagePath);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . '/*') as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($path);
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection();
        $payloadCollection->add(StrictDataObject::from(['test_data' => 'batch_test']));

        return new TaskPayloadRecord(type: 'test', data: $payloadCollection);
    }

    private function createUniqueTask(int $number, string $signature = 'test-task'): TaskRecord
    {
        return new TaskRecord(
            id: new TaskIdVO($this->generateUuid($number)),
            signature: new TaskSignatureVO($signature),
            class: TestTask::class,
            payload: $this->createTaskPayload(),
            status: TaskStatus::PENDING,
            created_at: new Iso8601DateTimeVO(),
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 minute'))),
            end_at: new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
            delay_seconds: new CounterVO(0),
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );
    }

    private function createFailingUniqueTask(int $number): TaskRecord
    {
        return new TaskRecord(
            id: new TaskIdVO($this->generateUuid($number)),
            signature: new TaskSignatureVO('failing-task'),
            class: FailingTask::class,
            payload: $this->createTaskPayload(),
            status: TaskStatus::PENDING,
            created_at: new Iso8601DateTimeVO(),
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 minute'))),
            end_at: new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
            delay_seconds: new CounterVO(0),
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );
    }

    private function createRecurringTask(string $signature): RecurringTaskRecord
    {
        return new RecurringTaskRecord(
            signature: new TaskSignatureVO($signature),
            class: TestTask::class,
            payload: $this->createTaskPayload(),
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
            end_at: null,
            delay_seconds: new CounterVO(300),
            last_run_at: null,
            next_run_at: new Iso8601DateTimeVO(date('c', strtotime('-5 minutes'))),
            success_count: new CounterVO(0),
            failure_count: new CounterVO(0),
        );
    }

    private function createTestTask(int $number): TaskRecord
    {
        return $this->createUniqueTask($number);
    }

    private function createRecurringTaskWithNextRun(string $signature, ?Carbon $nextRunAt = null): RecurringTaskRecord
    {
        $nextRun = $nextRunAt ?? Carbon::now()->subMinutes(5);

        return new RecurringTaskRecord(
            signature: new TaskSignatureVO($signature),
            class: TestTask::class,
            payload: $this->createTaskPayload(),
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
            end_at: null,
            delay_seconds: new CounterVO(300),
            last_run_at: null,
            next_run_at: new Iso8601DateTimeVO($nextRun->toIso8601String()),
            success_count: new CounterVO(0),
            failure_count: new CounterVO(0),
        );
    }

    // ==================== Basic Processing Tests ====================

    public function test_process_processes_all_pending_unique_tasks(): void
    {
        $this->setConfigDefaults();
        $this->createBatchService();

        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createUniqueTask($i);
            $this->taskRepository->save($task);
        }

        $record = $this->batch->process();

        $this->assertSame(3, $record->unique_success->value);
        $this->assertSame(0, $record->unique_failed->value);
        $this->assertSame(0, $record->recurring_success->value);
        $this->assertSame(0, $record->recurring_failed->value);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(0, $pending->count());
    }

    public function test_process_processes_all_pending_recurring_tasks(): void
    {
        $this->setConfigDefaults();
        $this->createBatchService();

        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createRecurringTask("recurring-{$i}");
            $this->recurringTaskRepository->save($task);
        }

        $record = $this->batch->process();

        $this->assertSame(0, $record->unique_success->value);
        $this->assertSame(0, $record->unique_failed->value);
        $this->assertSame(3, $record->recurring_success->value);
        $this->assertSame(0, $record->recurring_failed->value);
    }

    // ==================== Filtering Tests ====================

    public function test_process_unique_only_processes_only_unique_tasks(): void
    {
        $this->setConfigDefaults();
        $this->createBatchService();

        $uniqueTask = $this->createUniqueTask(1);
        $recurringTask = $this->createRecurringTask('recurring-1');
        $this->taskRepository->save($uniqueTask);
        $this->recurringTaskRepository->save($recurringTask);

        $record = $this->batch->processUniqueOnly();

        $this->assertSame(1, $record->unique_success->value);
        $this->assertSame(0, $record->recurring_success->value);

        $recurring = $this->recurringTaskRepository->findAll();
        $this->assertSame(1, $recurring->count());
    }

    public function test_process_recurring_only_processes_only_recurring_tasks(): void
    {
        $this->setConfigDefaults();
        $this->createBatchService();

        $uniqueTask = $this->createUniqueTask(2);
        $recurringTask = $this->createRecurringTask('recurring-1');
        $this->taskRepository->save($uniqueTask);
        $this->recurringTaskRepository->save($recurringTask);

        $record = $this->batch->processRecurringOnly();

        $this->assertSame(0, $record->unique_success->value);
        $this->assertSame(1, $record->recurring_success->value);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(1, $pending->count());
    }

    // ==================== Error Handling Tests ====================

    public function test_process_handles_failing_tasks_gracefully(): void
    {
        $this->setConfigDefaults();
        $this->createBatchService();

        $successTask = $this->createUniqueTask(3);
        $failingTask = $this->createFailingUniqueTask(4);
        $this->taskRepository->save($successTask);
        $this->taskRepository->save($failingTask);

        $record = $this->batch->process();

        $this->assertSame(1, $record->unique_success->value);
        $this->assertSame(1, $record->unique_failed->value);
        $this->assertFalse($record->unique_errors->isEmpty());
    }

    // ==================== Statistics Tests ====================

    public function test_process_returns_correct_statistics(): void
    {
        $this->setConfigDefaults();
        $this->createBatchService();

        for ($i = 1; $i <= 2; $i++) {
            $task = $this->createUniqueTask(10 + $i);
            $this->taskRepository->save($task);
        }
        for ($i = 1; $i <= 2; $i++) {
            $task = $this->createRecurringTask("recurring-{$i}");
            $this->recurringTaskRepository->save($task);
        }

        $record = $this->batch->process();

        $this->assertSame(2, $record->unique_success->value);
        $this->assertSame(0, $record->unique_failed->value);
        $this->assertSame(2, $record->recurring_success->value);
        $this->assertSame(0, $record->recurring_failed->value);
    }

    // ==================== Empty Queue Tests ====================

    public function test_process_empty_queue_returns_empty_result(): void
    {
        $this->setConfigDefaults();
        $this->createBatchService();

        $record = $this->batch->process();

        $this->assertSame(0, $record->unique_success->value);
        $this->assertSame(0, $record->unique_failed->value);
        $this->assertSame(0, $record->recurring_success->value);
        $this->assertSame(0, $record->recurring_failed->value);
        $this->assertTrue($record->unique_errors->isEmpty());
        $this->assertTrue($record->recurring_errors->isEmpty());
    }

    public function test_process_unique_only_on_empty_queue(): void
    {
        $this->setConfigDefaults();
        $this->createBatchService();

        $record = $this->batch->processUniqueOnly();

        $this->assertSame(0, $record->unique_success->value);
        $this->assertSame(0, $record->unique_failed->value);
        $this->assertTrue($record->unique_errors->isEmpty());
    }

    public function test_process_recurring_only_on_empty_queue(): void
    {
        $this->setConfigDefaults();
        $this->createBatchService();

        $record = $this->batch->processRecurringOnly();

        $this->assertSame(0, $record->recurring_success->value);
        $this->assertSame(0, $record->recurring_failed->value);
        $this->assertTrue($record->recurring_errors->isEmpty());
    }

    // ==================== Limit Handling Tests ====================

    public function test_batch_respects_config_limit(): void
    {
        $this->setConfigDefaults(['task.batch.limit' => 3]);
        $this->createBatchService();

        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask(20 + $i);
            $this->taskRepository->save($task);
        }

        $record = $this->batch->process();

        $totalProcessed = $record->unique_success->value + $record->unique_failed->value
            + $record->recurring_success->value + $record->recurring_failed->value;

        $this->assertSame(3, $totalProcessed);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(7, $pending->count());
    }

    public function test_batch_with_custom_limit_overrides_config(): void
    {
        $this->setConfigDefaults(['task.batch.limit' => 3]);
        $this->createBatchService();

        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask(40 + $i);
            $this->taskRepository->save($task);
        }

        $record = $this->batch->process(5);

        $totalProcessed = $record->unique_success->value + $record->unique_failed->value
            + $record->recurring_success->value + $record->recurring_failed->value;

        $this->assertSame(5, $totalProcessed);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(5, $pending->count());
    }

    public function test_batch_with_limit_zero_processes_nothing(): void
    {
        $this->setConfigDefaults();
        $this->createBatchService();

        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask(60 + $i);
            $this->taskRepository->save($task);
        }

        $record = $this->batch->process(0);

        $totalProcessed = $record->unique_success->value + $record->unique_failed->value
            + $record->recurring_success->value + $record->recurring_failed->value;

        $this->assertSame(0, $totalProcessed);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(10, $pending->count());
    }

    public function test_batch_processes_oldest_tasks_first_with_limit(): void
    {
        $this->setConfigDefaults(['task.batch.limit' => 1000]);
        $this->createBatchService();

        $task1 = $this->createTestTask(80);
        $task2 = $this->createTestTask(81);
        $task3 = $this->createTestTask(82);

        $this->taskRepository->save($task1);
        $this->taskRepository->save($task2);
        $this->taskRepository->save($task3);

        $record = $this->batch->process(2);

        $totalProcessed = $record->unique_success->value + $record->unique_failed->value
            + $record->recurring_success->value + $record->recurring_failed->value;

        $this->assertSame(2, $totalProcessed);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(1, $pending->count());
    }

    public function test_batch_processes_newest_tasks_first_when_configured(): void
    {
        $this->setConfigDefaults([
            'task.batch.limit' => 1000,
            'task.batch.order' => 'newest',
        ]);
        $this->createBatchService();

        $task1 = $this->createTestTask(90);
        $task2 = $this->createTestTask(91);
        $task3 = $this->createTestTask(92);

        $this->taskRepository->save($task1);
        $this->taskRepository->save($task2);
        $this->taskRepository->save($task3);

        $record = $this->batch->process(2);

        $totalProcessed = $record->unique_success->value + $record->unique_failed->value
            + $record->recurring_success->value + $record->recurring_failed->value;

        $this->assertSame(2, $totalProcessed);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(1, $pending->count());
    }

    public function test_batch_unique_only_respects_limit(): void
    {
        $this->setConfigDefaults(['task.batch.limit' => 1000]);
        $this->createBatchService();

        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTask(100 + $i);
            $this->taskRepository->save($task);
        }

        $record = $this->batch->processUniqueOnly(4);

        $totalProcessed = $record->unique_success->value + $record->unique_failed->value;

        $this->assertSame(4, $totalProcessed);
    }

    public function test_batch_recurring_only_respects_limit(): void
    {
        $this->setConfigDefaults(['task.batch.limit' => 1000]);
        $this->createBatchService();

        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createRecurringTaskWithNextRun("recurring-{$i}");
            $this->recurringTaskRepository->save($task);
        }

        $record = $this->batch->processRecurringOnly(4);

        $totalProcessed = $record->recurring_success->value + $record->recurring_failed->value;

        $this->assertSame(4, $totalProcessed);
    }

    public function test_batch_limit_with_more_tasks_than_limit(): void
    {
        $this->setConfigDefaults(['task.batch.limit' => 1000]);
        $this->createBatchService();

        for ($i = 1; $i <= 20; $i++) {
            $task = $this->createTestTask(200 + $i);
            $this->taskRepository->save($task);
        }

        $record = $this->batch->process(7);

        $totalProcessed = $record->unique_success->value + $record->unique_failed->value
            + $record->recurring_success->value + $record->recurring_failed->value;

        $this->assertSame(7, $totalProcessed);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(13, $pending->count());
    }

    public function test_batch_limit_with_exact_number(): void
    {
        $this->setConfigDefaults(['task.batch.limit' => 1000]);
        $this->createBatchService();

        for ($i = 1; $i <= 5; $i++) {
            $task = $this->createTestTask(300 + $i);
            $this->taskRepository->save($task);
        }

        $record = $this->batch->process(5);

        $totalProcessed = $record->unique_success->value + $record->unique_failed->value
            + $record->recurring_success->value + $record->recurring_failed->value;

        $this->assertSame(5, $totalProcessed);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(0, $pending->count());
    }
}
