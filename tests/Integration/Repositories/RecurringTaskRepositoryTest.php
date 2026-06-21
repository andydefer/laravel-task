<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Repositories;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Strategies\RecurringTaskPathStrategy;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class RecurringTaskRepositoryTest extends IntegrationTestCase
{
    private RecurringTaskRepository $repository;

    private string $baseStoragePath;

    private FileSystemService $fs;

    private array $createdAliases = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fs = new FileSystemService;
        $this->baseStoragePath = storage_path('test');

        if ($this->fs->isDirectory($this->baseStoragePath)) {
            $this->fs->deleteDirectory($this->baseStoragePath);
        }
        $this->fs->makeDirectory($this->baseStoragePath, PermissionMode::DIRECTORY, true);

        $pathStrategy = new RecurringTaskPathStrategy($this->baseStoragePath);
        $jsonlContext = new JsonlContext;

        $jsonl = new JsonlService(
            pathStrategy: $pathStrategy,
            fileSystem: $this->fs,
            context: $jsonlContext,
            defaultBufferSize: 100,
        );

        $hydration = new HydrationService;

        $this->repository = new RecurringTaskRepository(
            $jsonl,
            $hydration,
            $this->fs,
            $this->baseStoragePath,
        );

        $this->createdAliases = [];
    }

    protected function tearDown(): void
    {

        if (! empty($this->createdAliases)) {
            foreach ($this->createdAliases as $alias) {
                $this->repository->delete(new TaskSignatureVO($alias));
            }
        }

        if ($this->fs->isDirectory($this->baseStoragePath)) {
            $this->fs->deleteDirectory($this->baseStoragePath);
        }
        parent::tearDown();
    }

    private function createTaskRecord(string $alias): RecurringTaskRecord
    {
        return new RecurringTaskRecord(
            alias: new TaskSignatureVO($alias),
            fqcn: 'TestRecurringTask',
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            status: RecurringTaskStatus::PENDING,
        );
    }

    private function trackAlias(string $alias): void
    {
        $this->createdAliases[] = $alias;
    }

    public function test_saves_task_adds_line_not_overwrite(): void
    {

        $alias = 'test-recurring-save';
        $this->trackAlias($alias);

        $task = $this->createTaskRecord($alias);
        $this->repository->save($task);

        $path = $this->baseStoragePath.'/recurring/pending/'.$alias.'.jsonl';
        $this->assertTrue($this->fs->exists($path));

        $updatedTask = new RecurringTaskRecord(
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            interval_seconds: $task->interval_seconds,
            start_at: $task->start_at,
            status: RecurringTaskStatus::PENDING,
            last_run_at: new Iso8601DateTimeVO(now()->toIso8601String()),
        );
        $this->repository->save($updatedTask);

        $content = $this->fs->get($path);
        $lines = array_filter(explode("\n", $content));

        $this->assertCount(2, $lines, 'Le fichier doit contenir 2 lignes');

        $lastLine = json_decode(end($lines), true);
        $this->assertNotNull($lastLine['last_run_at']);
    }

    public function test_finds_task_returns_last_version(): void
    {

        $alias = 'test-recurring-find';
        $this->trackAlias($alias);

        $task1 = $this->createTaskRecord($alias);
        $this->repository->save($task1);

        $task2 = new RecurringTaskRecord(
            alias: $task1->alias,
            fqcn: $task1->fqcn,
            payload: $task1->payload,
            interval_seconds: $task1->interval_seconds,
            start_at: $task1->start_at,
            status: RecurringTaskStatus::PENDING,
            last_run_at: new Iso8601DateTimeVO(now()->toIso8601String()),
        );
        $this->repository->save($task2);

        $found = $this->repository->find(new TaskSignatureVO($alias));
        $this->assertNotNull($found);
        $this->assertNotNull($found->last_run_at);
    }

    public function test_finds_all_versions(): void
    {

        $alias = 'test-recurring-versions';
        $this->trackAlias($alias);

        for ($i = 0; $i < 3; $i++) {
            $task = new RecurringTaskRecord(
                alias: new TaskSignatureVO($alias),
                fqcn: 'TestRecurringTask',
                payload: StrictDataObject::from(['version' => $i]),
                interval_seconds: new CounterVO(3600),
                start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
                status: RecurringTaskStatus::PENDING,
                last_run_at: $i > 0 ? new Iso8601DateTimeVO(now()->toIso8601String()) : null,
            );
            $this->repository->save($task);
        }

        $versions = $this->repository->findAllVersions(new TaskSignatureVO($alias));
        $this->assertCount(3, $versions);
    }

    public function test_moves_to_running_moves_file_with_history(): void
    {

        $alias = 'test-recurring-running';
        $this->trackAlias($alias);

        $task = $this->createTaskRecord($alias);
        $this->repository->save($task);

        // ✅ Ajouter une deuxième version avant le move
        $updatedTask = new RecurringTaskRecord(
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            interval_seconds: $task->interval_seconds,
            start_at: $task->start_at,
            status: RecurringTaskStatus::PENDING,
            last_run_at: new Iso8601DateTimeVO(now()->toIso8601String()),
        );
        $this->repository->save($updatedTask);

        $this->repository->moveToRunning(new TaskSignatureVO($alias), $task);

        $sourcePath = $this->baseStoragePath.'/recurring/pending/'.$alias.'.jsonl';
        $this->assertFalse($this->fs->exists($sourcePath), 'Le fichier source ne doit PAS exister dans pending');

        $targetPath = $this->baseStoragePath.'/recurring/running/'.$alias.'.jsonl';
        $this->assertTrue($this->fs->exists($targetPath), 'Le fichier target doit exister dans running');

        $content = $this->fs->get($targetPath);
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(3, $lines, 'Le fichier doit contenir 3 lignes (2 versions + 1 move)');

        $lastLine = json_decode(end($lines), true);
        $this->assertEquals(RecurringTaskStatus::RUNNING->value, $lastLine['status']);
    }

    public function test_moves_to_finished_moves_file_with_history(): void
    {

        $alias = 'test-recurring-finished';
        $this->trackAlias($alias);

        $task = $this->createTaskRecord($alias);
        $this->repository->save($task);

        $this->repository->moveToRunning(new TaskSignatureVO($alias), $task);

        $task = $this->repository->find(new TaskSignatureVO($alias));
        $this->repository->moveToFinished(new TaskSignatureVO($alias), $task);

        $targetPath = $this->baseStoragePath.'/recurring/finished/'.$alias.'.jsonl';
        $this->assertTrue($this->fs->exists($targetPath));

        $content = $this->fs->get($targetPath);
        $lines = array_filter(explode("\n", $content));

        $lastLine = json_decode(end($lines), true);
        $this->assertEquals(RecurringTaskStatus::FINISHED->value, $lastLine['status']);
        $this->assertNotNull($lastLine['finished_at']);
    }

    public function test_update_after_run_adds_debug_and_keeps_pending(): void
    {
        $alias = 'test-recurring-update';
        $this->trackAlias($alias);

        $task = $this->createTaskRecord($alias);
        $this->repository->save($task);

        $this->repository->updateAfterRun($task, true);

        $found = $this->repository->find(new TaskSignatureVO($alias));
        $this->assertNotNull($found);

        $this->assertEquals(RecurringTaskStatus::PENDING, $found->status);
        $this->assertGreaterThan(0, $found->debug->count());

        /** @var mixed $debugEntries */
        $debugEntries = array_values(iterator_to_array($found->debug));

        /** @var mixed $firstDebug */
        $firstDebug = $debugEntries[0];
        $this->assertEquals(ExecutionStatus::SUCCEEDED, $firstDebug->status);
        $this->assertEquals('Task executed successfully', $firstDebug->info);
    }

    public function test_find_ready_to_run_calculates_next_run(): void
    {

        $alias1 = 'test-recurring-ready-1';
        $alias2 = 'test-recurring-ready-2';

        $this->trackAlias($alias1);
        $this->trackAlias($alias2);

        $task1 = new RecurringTaskRecord(
            alias: new TaskSignatureVO($alias1),
            fqcn: 'TestRecurringTask',
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PENDING,
            last_run_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
        );
        $this->repository->save($task1);

        $task2 = new RecurringTaskRecord(
            alias: new TaskSignatureVO($alias2),
            fqcn: 'TestRecurringTask',
            payload: StrictDataObject::from(['test' => 'recurring']),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PENDING,
            last_run_at: new Iso8601DateTimeVO(now()->toIso8601String()),
        );
        $this->repository->save($task2);

        $ready = $this->repository->findReadyToRun(date('c'));
        $this->assertCount(1, $ready);
        $this->assertEquals($alias1, $ready->first()->alias->value);
    }

    public function test_counts_tasks_by_status(): void
    {

        // PENDING
        for ($i = 0; $i < 3; $i++) {
            $alias = 'pending-'.$i;
            $this->trackAlias($alias);
            $task = $this->createTaskRecord($alias);
            $this->repository->save($task);
        }

        // RUNNING
        for ($i = 0; $i < 2; $i++) {
            $alias = 'running-'.$i;
            $this->trackAlias($alias);
            $task = $this->createTaskRecord($alias);
            $this->repository->save($task);
            $this->repository->moveToRunning(new TaskSignatureVO($alias), $task);
        }

        // FINISHED
        for ($i = 0; $i < 1; $i++) {
            $alias = 'finished-'.$i;
            $this->trackAlias($alias);
            $task = $this->createTaskRecord($alias);
            $this->repository->save($task);
            $this->repository->moveToRunning(new TaskSignatureVO($alias), $task);
            $found = $this->repository->find(new TaskSignatureVO($alias));
            $this->repository->moveToFinished(new TaskSignatureVO($alias), $found);
        }

        $this->assertEquals(3, $this->repository->countPending());
        $this->assertEquals(2, $this->repository->countRunning());
        $this->assertEquals(1, $this->repository->countFinished());
        $this->assertEquals(6, $this->repository->count());
    }

    public function test_deletes_task_removes_all_versions(): void
    {

        $alias = 'test-recurring-delete';
        $this->trackAlias($alias);

        for ($i = 0; $i < 3; $i++) {
            $task = new RecurringTaskRecord(
                alias: new TaskSignatureVO($alias),
                fqcn: 'TestRecurringTask',
                payload: StrictDataObject::from(['version' => $i]),
                interval_seconds: new CounterVO(3600),
                start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
                status: RecurringTaskStatus::PENDING,
                last_run_at: $i > 0 ? new Iso8601DateTimeVO(now()->toIso8601String()) : null,
            );
            $this->repository->save($task);
        }

        $versions = $this->repository->findAllVersions(new TaskSignatureVO($alias));
        $this->assertCount(3, $versions);

        $this->repository->delete(new TaskSignatureVO($alias));

        $versions = $this->repository->findAllVersions(new TaskSignatureVO($alias));
        $this->assertCount(0, $versions);

        $path = $this->baseStoragePath.'/recurring/pending/'.$alias.'.jsonl';
        $this->assertFalse($this->fs->exists($path));
    }

    public function test_moves_to_pending_from_running_moves_file_with_history(): void
    {

        $alias = 'test-recurring-back-to-pending';
        $this->trackAlias($alias);

        $task = $this->createTaskRecord($alias);
        $this->repository->save($task);

        $this->repository->moveToRunning(new TaskSignatureVO($alias), $task);

        $task = $this->repository->find(new TaskSignatureVO($alias));
        $this->repository->moveToPending(new TaskSignatureVO($alias), $task);

        $targetPath = $this->baseStoragePath.'/recurring/pending/'.$alias.'.jsonl';
        $this->assertTrue($this->fs->exists($targetPath));

        $content = $this->fs->get($targetPath);
        $lines = array_filter(explode("\n", $content));

        $lastLine = json_decode(end($lines), true);
        $this->assertEquals(RecurringTaskStatus::PENDING->value, $lastLine['status']);
    }
}
