<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Workflows;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contexts\TaskStorageContext;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Strategies\TaskPathStrategy;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class RecurringTaskTest extends IntegrationTestCase
{
    private RecurringTaskRepositoryInterface $recurringTaskRepository;
    private TaskRunnerService $runner;
    private TaskValidatorService $validator;
    private string $storagePath;
    private TaskConfigInterface $config;
    private ConfigRepository $configRepository;
    private HydrationService $hydration;
    private FileSystemInterface $fs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . '/task_storage_' . uniqid();
        $this->configRepository = $this->app->make(ConfigRepository::class);
        $this->hydration = new HydrationService();
        $this->fs = new FileSystemService();

        $this->setConfigDefaults();

        $this->config = new TaskConfig($this->configRepository);

        $context = new TaskStorageContext($this->config);
        $strategy = new TaskPathStrategy($this->config->storagePath());
        $jsonlContext = new JsonlContext();
        $jsonlService = new JsonlService(
            pathStrategy: $strategy,
            fileSystem: $this->fs,
            context: $jsonlContext,
        );

        $this->recurringTaskRepository = new RecurringTaskRepository(
            context: $context,
            jsonl: $jsonlService,
            hydration: $this->hydration,
            fs: $this->fs,
        );

        $logger = $this->app->make(LoggerInterface::class);
        $this->validator = new TaskValidatorService(
            config: $this->config,
            hydration: $this->hydration,
            logger: $logger,
            app: $this->app,
        );

        $this->runner = new TaskRunnerService(
            taskRepository: $this->app->make(\AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface::class),
            recurringTaskRepository: $this->recurringTaskRepository,
            logger: $logger,
            validator: $this->validator,
            config: $this->config,
            hydration: $this->hydration,
            fs: $this->fs,
            app: $this->app,
        );
    }

    private function setConfigDefaults(): void
    {
        $this->configRepository->set('task.storage_path', $this->storagePath);
        $this->configRepository->set('task.storage_pending_path', $this->storagePath . '/pending');
        $this->configRepository->set('task.storage_recurring_path', $this->storagePath . '/recurring');
        $this->configRepository->set('task.storage_completed_path', $this->storagePath . '/completed');
        $this->configRepository->set('task.storage_grace_period_path', $this->storagePath . '/grace_period');
        $this->configRepository->set('task.grace_period.enabled', false);
        $this->configRepository->set('task.grace_period.seconds', 86400);
        $this->configRepository->set('task.batch.limit', 1000);
        $this->configRepository->set('task.batch.order', 'oldest');
    }

    protected function tearDown(): void
    {
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

        $files = glob($path . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $this->removeDirectory($file);
            }
        }

        rmdir($path);
    }

    private function createTaskPayload(?array $customData = null): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection();

        if ($customData !== null) {
            $payloadCollection->add(StrictDataObject::from($customData));
        } else {
            $payloadCollection->add(StrictDataObject::from([
                'test_data' => 'recurring_test',
            ]));
        }

        return new TaskPayloadRecord(
            type: 'test',
            data: $payloadCollection,
        );
    }

    private function createRecurringTask(
        string $signature,
        string $class,
        int $delaySeconds = 300,
        int $successCount = 0,
        int $failureCount = 0,
        ?string $startAt = null,
        ?string $endAt = null,
        ?string $lastRunAt = null,
        ?string $nextRunAt = null
    ): RecurringTaskRecord {
        $payload = $this->createTaskPayload();

        return new RecurringTaskRecord(
            signature: new TaskSignatureVO($signature),
            class: $class,
            payload: $payload,
            start_at: $startAt !== null ? new Iso8601DateTimeVO($startAt) : new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
            end_at: $endAt !== null ? new Iso8601DateTimeVO($endAt) : null,
            delay_seconds: new CounterVO($delaySeconds),
            last_run_at: $lastRunAt !== null ? new Iso8601DateTimeVO($lastRunAt) : null,
            next_run_at: $nextRunAt !== null ? new Iso8601DateTimeVO($nextRunAt) : new Iso8601DateTimeVO(date('c', strtotime('-5 minutes'))),
            success_count: new CounterVO($successCount),
            failure_count: new CounterVO($failureCount),
        );
    }

    public function test_recurring_task_updates_after_run(): void
    {
        $task = $this->createRecurringTask(
            signature: 'recurring-test',
            class: TestTask::class,
            delaySeconds: 300,
            nextRunAt: date('c', strtotime('-5 minutes'))
        );
        $this->recurringTaskRepository->save($task);

        $result = $this->runner->runRecurringTask($task);
        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-test'));

        $this->assertTrue($result);
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->success_count->value);
        $this->assertNotNull($updated->last_run_at);
        $this->assertNotNull($updated->next_run_at);
    }

    public function test_recurring_task_updates_next_run_at(): void
    {
        $task = $this->createRecurringTask(
            signature: 'recurring-next-run',
            class: TestTask::class,
            delaySeconds: 300,
            nextRunAt: date('c', strtotime('-10 minutes'))
        );
        $this->recurringTaskRepository->save($task);

        $oldNextRunAt = $task->next_run_at->value;

        $result = $this->runner->runRecurringTask($task);
        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-next-run'));

        $this->assertTrue($result);
        $this->assertNotNull($updated);
        $this->assertNotSame($oldNextRunAt, $updated->next_run_at->value);
        $this->assertGreaterThan(strtotime($oldNextRunAt), strtotime($updated->next_run_at->value));
    }

    public function test_recurring_task_increments_success_count(): void
    {
        $task = $this->createRecurringTask(
            signature: 'recurring-counter',
            class: TestTask::class,
            delaySeconds: 300,
            successCount: 5,
            failureCount: 2,
            nextRunAt: date('c', strtotime('-5 minutes'))
        );
        $this->recurringTaskRepository->save($task);

        $result = $this->runner->runRecurringTask($task);
        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-counter'));

        $this->assertTrue($result);
        $this->assertNotNull($updated);
        $this->assertSame(6, $updated->success_count->value);
        $this->assertSame(2, $updated->failure_count->value);
    }

    public function test_recurring_task_increments_failure_count_on_error(): void
    {
        $task = $this->createRecurringTask(
            signature: 'recurring-failing',
            class: FailingTask::class,
            delaySeconds: 300,
            successCount: 3,
            failureCount: 1,
            nextRunAt: date('c', strtotime('-5 minutes'))
        );
        $this->recurringTaskRepository->save($task);

        $result = $this->runner->runRecurringTask($task);
        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-failing'));

        $this->assertFalse($result);
        $this->assertNotNull($updated);
        $this->assertSame(3, $updated->success_count->value);
        $this->assertSame(2, $updated->failure_count->value);
        $this->assertNotNull($updated->last_error);
    }

    public function test_recurring_task_stops_when_end_at_reached(): void
    {
        $task = $this->createRecurringTask(
            signature: 'recurring-expired',
            class: TestTask::class,
            delaySeconds: 300,
            successCount: 10,
            failureCount: 0,
            startAt: date('c', strtotime('-2 days')),
            endAt: date('c', strtotime('-1 day')),
            nextRunAt: date('c', strtotime('-5 minutes'))
        );
        $this->recurringTaskRepository->save($task);

        $shouldRun = $this->validator->shouldRunRecurringNow($task);

        $this->assertFalse($shouldRun);
    }

    public function test_recurring_task_does_not_run_before_start_at(): void
    {
        $task = $this->createRecurringTask(
            signature: 'recurring-future',
            class: TestTask::class,
            delaySeconds: 300,
            startAt: date('c', strtotime('+1 hour')),
            nextRunAt: date('c')
        );
        $this->recurringTaskRepository->save($task);

        $shouldRun = $this->validator->shouldRunRecurringNow($task);

        $this->assertFalse($shouldRun);
    }

    public function test_recurring_task_maintains_payload_across_runs(): void
    {
        $customPayload = $this->createTaskPayload([
            'config_key' => 'test_value',
            'numeric_value' => 42,
        ]);

        $task = new RecurringTaskRecord(
            signature: new TaskSignatureVO('recurring-payload'),
            class: TestTask::class,
            payload: $customPayload,
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
            end_at: null,
            delay_seconds: new CounterVO(300),
            last_run_at: null,
            next_run_at: new Iso8601DateTimeVO(date('c', strtotime('-5 minutes'))),
            success_count: new CounterVO(0),
            failure_count: new CounterVO(0),
        );

        $this->recurringTaskRepository->save($task);

        $this->runner->runRecurringTask($task);
        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-payload'));

        $this->assertNotNull($updated);
        $this->assertSame($task->payload->type, $updated->payload->type);
        $this->assertSame($task->payload->data->count(), $updated->payload->data->count());
    }

    public function test_multiple_recurring_tasks_can_coexist(): void
    {
        $task1 = $this->createRecurringTask(
            signature: 'recurring-task-1',
            class: TestTask::class,
            delaySeconds: 300,
            nextRunAt: date('c', strtotime('-5 minutes'))
        );

        $task2 = $this->createRecurringTask(
            signature: 'recurring-task-2',
            class: TestTask::class,
            delaySeconds: 600,
            nextRunAt: date('c', strtotime('-5 minutes'))
        );

        $this->recurringTaskRepository->save($task1);
        $this->recurringTaskRepository->save($task2);

        $result1 = $this->runner->runRecurringTask($task1);
        $result2 = $this->runner->runRecurringTask($task2);

        $updated1 = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-task-1'));
        $updated2 = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-task-2'));

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertSame(1, $updated1->success_count->value);
        $this->assertSame(1, $updated2->success_count->value);
    }

    public function test_recurring_task_respects_delay_seconds(): void
    {
        $delaySeconds = 600;

        $task = $this->createRecurringTask(
            signature: 'recurring-delay',
            class: TestTask::class,
            delaySeconds: $delaySeconds,
            lastRunAt: date('c', strtotime('-10 minutes')),
            nextRunAt: date('c', strtotime('-5 minutes'))
        );
        $this->recurringTaskRepository->save($task);

        $oldNextRunAt = $task->next_run_at->value;

        $this->runner->runRecurringTask($task);
        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-delay'));

        $this->assertNotNull($updated);
        $this->assertGreaterThanOrEqual(
            $delaySeconds,
            strtotime($updated->next_run_at->value) - strtotime($oldNextRunAt)
        );
    }
}
