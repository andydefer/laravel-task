<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Processors;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Loggers\UniqueTaskLogger;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Processors\UniqueTaskProcessor;
use AndyDefer\Task\Records\UniqueTaskFiltersRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Runners\UniqueTaskRunner;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingUniqueTaskForProcessor;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\UniqueTaskValidator;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Ramsey\Uuid\Uuid;

final class UniqueTaskProcessorTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private UniqueTaskProcessor $processor;

    private UniqueTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 7, 5, 18, 58, 52));

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new UniqueTaskRepository(
            $this->debugRepository,
            App::make(LoggerInterface::class)
        );

        $validator = new UniqueTaskValidator;

        $logger = new UniqueTaskLogger(
            logger: App::make(LoggerInterface::class),
            hydration: App::make(HydrationService::class),
        );

        $runner = new UniqueTaskRunner(
            validator: $validator,
            logger: $logger,
            hydration: App::make(HydrationService::class),
            app: App::getFacadeApplication(),
            repository: $this->repository,
        );

        $this->processor = new UniqueTaskProcessor(
            repository: $this->repository,
            runner: $runner,
            validator: $validator,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function getUuidForAlias(string $aliasName): string
    {
        return Uuid::uuid4()->toString();
    }

    private function generateAliasFromName(string $name, ?string $uuid = null): TaskAliasVO
    {
        $uuid = $uuid ?? $this->getUuidForAlias($name);

        return new TaskAliasVO('unique@'.$uuid);
    }

    private function findTaskByAlias(TaskAliasVO $alias): ?UniqueTask
    {
        $filters = UniqueTaskFiltersRecord::from([
            'alias' => $alias->getValue(),
        ]);

        $results = $this->repository->findBy(
            FindByRecord::from(['filters' => $filters])
        );

        return $results->first() ?? null;
    }

    private function createAndSaveTask(
        string $aliasName,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?Iso8601DateTimeVO $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3,
        ?string $fqcn = null
    ): UniqueTaskRecord {
        $scheduledAt = $scheduledAt ?? (new Iso8601DateTimeVO)->addSeconds(-7200);
        $id = $id ?? $this->getUuidForAlias($aliasName);
        $fqcn = $fqcn ?? TestUniqueTask::class;
        $alias = $this->generateAliasFromName($aliasName, $id);

        $task = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $alias,
            'fqcn' => $fqcn,
            'payload' => StrictDataObject::from(['test' => 'unique']),
            'scheduled_at' => $scheduledAt,
            'grace_period_seconds' => $gracePeriodSeconds,
            'status' => $status,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
        ]);

        $this->repository->create($task);

        return $task;
    }

    private function createFailingTask(
        string $aliasName,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?Iso8601DateTimeVO $scheduledAt = null,
        int $gracePeriodSeconds = 86400
    ): UniqueTaskRecord {
        $scheduledAt = $scheduledAt ?? (new Iso8601DateTimeVO)->addSeconds(-7200);
        $id = $id ?? $this->getUuidForAlias($aliasName);
        $alias = $this->generateAliasFromName($aliasName, $id);

        $task = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $alias,
            'fqcn' => FailingUniqueTaskForProcessor::class,
            'payload' => StrictDataObject::from(['test' => 'failing']),
            'scheduled_at' => $scheduledAt,
            'grace_period_seconds' => $gracePeriodSeconds,
            'status' => $status,
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $this->repository->create($task);

        return $task;
    }

    // ==================== TESTS ====================

    public function test_process_executes_ready_tasks(): void
    {
        $now = new Iso8601DateTimeVO;

        $task1 = $this->createAndSaveTask(
            'ready-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200)
        );

        $task2 = $this->createAndSaveTask(
            'ready-2',
            null,
            UniqueTaskStatus::PENDING,
            $now
        );

        $task3 = $this->createAndSaveTask(
            'not-ready-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(7200)
        );

        $result = $this->processor->process();

        $this->assertEquals(2, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $task1Updated = $this->findTaskByAlias($task1->alias);
        $this->assertNotNull($task1Updated);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task1Updated->getStatus());

        $task2Updated = $this->findTaskByAlias($task2->alias);
        $this->assertNotNull($task2Updated);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task2Updated->getStatus());

        $task3Updated = $this->findTaskByAlias($task3->alias);
        $this->assertNotNull($task3Updated);
        $this->assertEquals(UniqueTaskStatus::PENDING, $task3Updated->getStatus());
    }

    public function test_process_handles_task_failure(): void
    {
        $now = new Iso8601DateTimeVO;

        $task = $this->createFailingTask(
            'failing-task',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200)
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(1, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $taskUpdated = $this->findTaskByAlias($task->alias);
        $this->assertNotNull($taskUpdated);
        $this->assertEquals(UniqueTaskStatus::FAILED, $taskUpdated->getStatus());
        $this->assertNotNull($taskUpdated->getFinishedAt());

        $this->assertGreaterThan(0, $result->errors->count());
        $error = $result->errors->first();
        $this->assertEquals('Test exception', $error->description->getValue());
    }

    public function test_process_respects_limit(): void
    {
        $now = new Iso8601DateTimeVO;
        $tasks = [];

        for ($i = 1; $i <= 5; $i++) {
            $tasks[] = $this->createAndSaveTask(
                "ready-{$i}",
                null,
                UniqueTaskStatus::PENDING,
                $now->addSeconds(-7200)
            );
        }

        $result = $this->processor->process(new LimitVO(3));

        $this->assertEquals(3, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $completedCount = 0;
        foreach ($tasks as $task) {
            $taskUpdated = $this->findTaskByAlias($task->alias);
            if ($taskUpdated !== null && $taskUpdated->getStatus() === UniqueTaskStatus::COMPLETED) {
                $completedCount++;
            }
        }
        $this->assertEquals(3, $completedCount);
    }

    public function test_process_handles_expired_tasks(): void
    {
        $now = new Iso8601DateTimeVO;

        $task1 = $this->createAndSaveTask(
            'expired-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-172800),
            3600
        );

        $task2 = $this->createAndSaveTask(
            'not-expired-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-43200),
            86400
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->getValue());
        $this->assertEquals(1, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $expiredTask = $this->findTaskByAlias($task1->alias);
        $this->assertNotNull($expiredTask);
        $this->assertEquals(UniqueTaskStatus::FAILED, $expiredTask->getStatus());

        $notExpiredTask = $this->findTaskByAlias($task2->alias);
        $this->assertNotNull($notExpiredTask);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $notExpiredTask->getStatus());
    }

    public function test_process_skips_tasks_with_max_attempts_reached(): void
    {
        $now = new Iso8601DateTimeVO;

        $task1 = $this->createAndSaveTask(
            'max-attempts-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200),
            86400,
            3,
            3
        );

        $task2 = $this->createAndSaveTask(
            'normal-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200),
            86400,
            0,
            3
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->getValue());
        $this->assertEquals(1, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $maxAttemptsTask = $this->findTaskByAlias($task1->alias);
        $this->assertNotNull($maxAttemptsTask);
        $this->assertEquals(UniqueTaskStatus::FAILED, $maxAttemptsTask->getStatus());

        $normalTask = $this->findTaskByAlias($task2->alias);
        $this->assertNotNull($normalTask);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $normalTask->getStatus());
    }

    public function test_process_adds_debug_for_each_execution(): void
    {
        $now = new Iso8601DateTimeVO;

        $id = $this->getUuidForAlias('debug-task');
        $task = $this->createAndSaveTask(
            'debug-task',
            $id,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200)
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->getValue());

        $debugs = $this->debugRepository->findByAlias($task->alias);

        $this->assertCount(1, $debugs);

        $debug = $debugs->first();
        $debugData = $debug->getData();

        $this->assertEquals('succeeded', $debug->getStatus()->value);
        $this->assertEquals('Task executed successfully', $debugData->toArray()['info']);
    }

    public function test_process_handles_mixed_scenario(): void
    {
        $now = new Iso8601DateTimeVO;

        $task1 = $this->createAndSaveTask(
            'mixed-success',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200)
        );

        $task2 = $this->createFailingTask(
            'mixed-failing',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200)
        );

        $task3 = $this->createAndSaveTask(
            'mixed-expired',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-172800),
            3600
        );

        $task4 = $this->createAndSaveTask(
            'mixed-max-attempts',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200),
            86400,
            3,
            3
        );

        $task5 = $this->createAndSaveTask(
            'mixed-future',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(7200)
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->getValue());
        $this->assertEquals(3, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());

        $task1Updated = $this->findTaskByAlias($task1->alias);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task1Updated->getStatus());

        $task2Updated = $this->findTaskByAlias($task2->alias);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task2Updated->getStatus());

        $task3Updated = $this->findTaskByAlias($task3->alias);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task3Updated->getStatus());

        $task4Updated = $this->findTaskByAlias($task4->alias);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task4Updated->getStatus());

        $task5Updated = $this->findTaskByAlias($task5->alias);
        $this->assertEquals(UniqueTaskStatus::PENDING, $task5Updated->getStatus());
    }

    public function test_process_records_errors_in_result(): void
    {
        $now = new Iso8601DateTimeVO;

        $id = $this->getUuidForAlias('error-task');
        $task = $this->createFailingTask(
            'error-task',
            $id,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200)
        );

        $result = $this->processor->process();

        $this->assertGreaterThan(0, $result->errors->count());

        $error = $result->errors->first();
        $this->assertEquals('Test exception', $error->description);
        $this->assertEquals($task->alias->getValue(), $error->alias);
    }

    public function test_process_does_not_execute_tasks_not_in_pending_status(): void
    {
        $now = new Iso8601DateTimeVO;

        $task1 = $this->createAndSaveTask(
            'completed-1',
            null,
            UniqueTaskStatus::COMPLETED,
            $now->addSeconds(-7200)
        );

        $task2 = $this->createAndSaveTask(
            'failed-1',
            null,
            UniqueTaskStatus::FAILED,
            $now->addSeconds(-7200)
        );

        $task3 = $this->createAndSaveTask(
            'pending-1',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200)
        );

        $result = $this->processor->process();

        $this->assertEquals(1, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());

        $task1Updated = $this->findTaskByAlias($task1->alias);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task1Updated->getStatus());

        $task2Updated = $this->findTaskByAlias($task2->alias);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task2Updated->getStatus());

        $task3Updated = $this->findTaskByAlias($task3->alias);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task3Updated->getStatus());
    }

    public function test_process_handles_empty_tasks(): void
    {
        $result = $this->processor->process();
        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());
        $this->assertCount(0, $result->errors);
    }

    public function test_process_moves_expired_tasks_to_failed_even_if_not_ready(): void
    {
        $now = new Iso8601DateTimeVO;

        $task = $this->createAndSaveTask(
            'expired-future',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-86400),
            3600
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(1, $result->failed->getValue());

        $taskUpdated = $this->findTaskByAlias($task->alias);
        $this->assertEquals(UniqueTaskStatus::FAILED, $taskUpdated->getStatus());
    }

    public function test_process_uses_validator_to_check_tasks_before_execution(): void
    {
        $now = new Iso8601DateTimeVO;

        $task = $this->createAndSaveTask(
            'validator-check',
            null,
            UniqueTaskStatus::PENDING,
            $now->addSeconds(-7200),
            86400,
            3,
            3
        );

        $result = $this->processor->process();

        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(1, $result->failed->getValue());

        $taskUpdated = $this->findTaskByAlias($task->alias);
        $this->assertEquals(UniqueTaskStatus::FAILED, $taskUpdated->getStatus());

        $this->assertGreaterThan(0, $result->errors->count());
        $error = $result->errors->first();
        $this->assertStringContainsString('Validation failed', $error->description->getValue());
    }
}
