<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Repositories;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Strategies\UniqueTaskPathStrategy;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class UniqueTaskRepositoryTest extends IntegrationTestCase
{
    private UniqueTaskRepository $repository;

    private string $baseStoragePath;

    private FileSystemService $fs;

    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fs = new FileSystemService;
        $this->baseStoragePath = storage_path('test');

        if ($this->fs->isDirectory($this->baseStoragePath)) {
            $this->fs->deleteDirectory($this->baseStoragePath);
        }
        $this->fs->makeDirectory($this->baseStoragePath, PermissionMode::DIRECTORY, true);

        $pathStrategy = new UniqueTaskPathStrategy($this->baseStoragePath);
        $jsonlContext = new JsonlContext;

        $jsonl = new JsonlService(
            pathStrategy: $pathStrategy,
            fileSystem: $this->fs,
            context: $jsonlContext,
            defaultBufferSize: 100,
        );

        $hydration = new HydrationService;

        $this->repository = new UniqueTaskRepository(
            $jsonl,
            $hydration,
            $this->fs,
            $this->baseStoragePath,
        );

        $this->createdIds = [];
    }

    protected function tearDown(): void
    {

        if (! empty($this->createdIds)) {
            foreach ($this->createdIds as $id) {
                $this->repository->delete(new TaskIdVO($id));
            }
        }

        if ($this->fs->isDirectory($this->baseStoragePath)) {
            $this->fs->deleteDirectory($this->baseStoragePath);
        }
        parent::tearDown();
    }

    private function createTaskRecord(
        string $id,
        string $alias = 'test-alias',
        string $status = 'pending'
    ): UniqueTaskRecord {
        return new UniqueTaskRecord(
            id: new TaskIdVO($id),
            alias: new TaskSignatureVO($alias),
            fqcn: 'TestTask',
            payload: StrictDataObject::from(['test' => 'data']),
            scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(30)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::from($status),
            max_attempts: new CounterVO(3),
        );
    }

    private function trackId(string $id): void
    {
        $this->createdIds[] = $id;
    }

    public function test_saves_task_adds_line_not_overwrite(): void
    {

        $id = '550e8400-e29b-41d4-a716-446655440000';
        $this->trackId($id);

        $task = $this->createTaskRecord($id);
        $this->repository->save($task);

        $path = $this->baseStoragePath.'/unique/pending/'.$id.'.jsonl';
        $this->assertTrue($this->fs->exists($path));

        $updatedTask = new UniqueTaskRecord(
            id: $task->id,
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            scheduled_at: $task->scheduled_at,
            grace_period_seconds: $task->grace_period_seconds,
            status: UniqueTaskStatus::PENDING,
            attempts: new CounterVO(1),
            max_attempts: $task->max_attempts,
        );
        $this->repository->save($updatedTask);

        $content = $this->fs->get($path);
        $lines = array_filter(explode("\n", $content));

        $this->assertCount(2, $lines, 'Le fichier doit contenir 2 lignes');

        $lastLine = json_decode(end($lines), true);
        $this->assertEquals(1, $lastLine['attempts']);
    }

    public function test_finds_task_returns_last_version(): void
    {

        $id = '550e8400-e29b-41d4-a716-446655440001';
        $this->trackId($id);

        $task1 = $this->createTaskRecord($id);
        $this->repository->save($task1);

        $task2 = new UniqueTaskRecord(
            id: $task1->id,
            alias: $task1->alias,
            fqcn: $task1->fqcn,
            payload: $task1->payload,
            scheduled_at: $task1->scheduled_at,
            grace_period_seconds: $task1->grace_period_seconds,
            status: UniqueTaskStatus::PENDING,
            attempts: new CounterVO(2),
            max_attempts: $task1->max_attempts,
        );
        $this->repository->save($task2);

        $found = $this->repository->find(new TaskIdVO($id));
        $this->assertNotNull($found);
        $this->assertEquals(2, $found->attempts->value);
    }

    public function test_finds_all_versions(): void
    {
        $id = '550e8400-e29b-41d4-a716-446655440002';
        $this->trackId($id);

        for ($i = 0; $i < 3; $i++) {
            $task = new UniqueTaskRecord(
                id: new TaskIdVO($id),
                alias: new TaskSignatureVO('test-alias'),
                fqcn: 'TestTask',
                payload: StrictDataObject::from(['version' => $i]),
                scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(30)->toIso8601String()),
                grace_period_seconds: 86400,
                status: UniqueTaskStatus::PENDING,
                attempts: new CounterVO($i),
                max_attempts: new CounterVO(3),
            );
            $this->repository->save($task);
        }

        $versions = $this->repository->findAllVersions(new TaskIdVO($id));
        $this->assertCount(3, $versions);

        /** @var mixed $versionsArray */
        $versionsArray = array_values(iterator_to_array($versions));

        /** @var UniqueTaskRecord $firstVersion */
        $firstVersion = $versionsArray[0];
        $this->assertSame(0, $firstVersion->attempts->value);

        /** @var UniqueTaskRecord $lastVersion */
        $lastVersion = end($versionsArray);
        $this->assertInstanceOf(UniqueTaskRecord::class, $lastVersion);
        $this->assertSame(2, $lastVersion->attempts->value);
    }

    public function test_moves_to_completed_moves_file_with_history(): void
    {

        $id = '550e8400-e29b-41d4-a716-446655440003';
        $this->trackId($id);

        $task = $this->createTaskRecord($id);
        $this->repository->save($task);

        // ✅ Ajouter une deuxième version avant le move
        $updatedTask = new UniqueTaskRecord(
            id: $task->id,
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            scheduled_at: $task->scheduled_at,
            grace_period_seconds: $task->grace_period_seconds,
            status: UniqueTaskStatus::PENDING,
            attempts: new CounterVO(1),
            max_attempts: $task->max_attempts,
        );
        $this->repository->save($updatedTask);

        $this->repository->moveToCompleted(new TaskIdVO($id), $task);

        // ✅ Vérifier que le fichier source n'existe PLUS dans pending
        $sourcePath = $this->baseStoragePath.'/unique/pending/'.$id.'.jsonl';
        $this->assertFalse($this->fs->exists($sourcePath), 'Le fichier source ne doit PAS exister dans pending');

        // ✅ Vérifier que le fichier target existe dans completed
        $targetPath = $this->baseStoragePath.'/unique/completed/'.$id.'.jsonl';
        $this->assertTrue($this->fs->exists($targetPath), 'Le fichier target doit exister dans completed');

        // ✅ Vérifier que l'historique est conservé
        $content = $this->fs->get($targetPath);
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(3, $lines, 'Le fichier doit contenir 3 lignes (2 versions + 1 move)');

        // ✅ Vérifier le statut de la dernière ligne
        $lastLine = json_decode(end($lines), true);
        $this->assertEquals(UniqueTaskStatus::COMPLETED->value, $lastLine['status']);
        $this->assertNotNull($lastLine['finished_at']);
    }

    public function test_finds_pending_tasks(): void
    {

        $ids = [
            '550e8400-e29b-41d4-a716-446655440005',
            '550e8400-e29b-41d4-a716-446655440006',
            '550e8400-e29b-41d4-a716-446655440007',
        ];

        foreach ($ids as $id) {
            $this->trackId($id);
            $task = $this->createTaskRecord($id);
            $this->repository->save($task);
        }

        $completedId = '550e8400-e29b-41d4-a716-446655449999';
        $this->trackId($completedId);
        $completedTask = $this->createTaskRecord($completedId);
        $this->repository->save($completedTask);
        $this->repository->moveToCompleted(new TaskIdVO($completedId), $completedTask);

        $pending = $this->repository->findPending();
        $this->assertCount(3, $pending);
    }

    public function test_finds_ready_to_run(): void
    {

        $id1 = '550e8400-e29b-41d4-a716-446655440010';
        $id2 = '550e8400-e29b-41d4-a716-446655440011';

        $this->trackId($id1);
        $this->trackId($id2);

        $task1 = new UniqueTaskRecord(
            id: new TaskIdVO($id1),
            alias: new TaskSignatureVO('ready-1'),
            fqcn: 'TestTask',
            payload: StrictDataObject::from(['test' => 'data']),
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
            max_attempts: new CounterVO(3),
        );
        $this->repository->save($task1);

        $task2 = new UniqueTaskRecord(
            id: new TaskIdVO($id2),
            alias: new TaskSignatureVO('not-ready'),
            fqcn: 'TestTask',
            payload: StrictDataObject::from(['test' => 'data']),
            scheduled_at: new Iso8601DateTimeVO(now()->addHours(24)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
            max_attempts: new CounterVO(3),
        );
        $this->repository->save($task2);

        $ready = $this->repository->findReadyToRun(date('c'));
        $this->assertCount(1, $ready);
        $this->assertEquals('ready-1', $ready->first()->alias->value);
    }

    public function test_finds_expired_tasks(): void
    {

        $id1 = '550e8400-e29b-41d4-a716-446655440012';
        $id2 = '550e8400-e29b-41d4-a716-446655440013';

        $this->trackId($id1);
        $this->trackId($id2);

        $task1 = new UniqueTaskRecord(
            id: new TaskIdVO($id1),
            alias: new TaskSignatureVO('expired-1'),
            fqcn: 'TestTask',
            payload: StrictDataObject::from(['test' => 'data']),
            scheduled_at: new Iso8601DateTimeVO(now()->subHours(48)->toIso8601String()),
            grace_period_seconds: 3600,
            status: UniqueTaskStatus::PENDING,
            max_attempts: new CounterVO(3),
        );
        $this->repository->save($task1);

        $task2 = new UniqueTaskRecord(
            id: new TaskIdVO($id2),
            alias: new TaskSignatureVO('not-expired'),
            fqcn: 'TestTask',
            payload: StrictDataObject::from(['test' => 'data']),
            scheduled_at: new Iso8601DateTimeVO(now()->addHours(2)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
            max_attempts: new CounterVO(3),
        );
        $this->repository->save($task2);

        $expired = $this->repository->findExpired(date('c'));
        $this->assertCount(1, $expired);
        $this->assertEquals('expired-1', $expired->first()->alias->value);
    }

    public function test_counts_tasks(): void
    {

        $pendingIds = [];
        for ($i = 0; $i < 3; $i++) {
            $id = '550e8400-e29b-41d4-a716-44665544'.str_pad((string) ($i + 20), 4, '0', STR_PAD_LEFT);
            $this->trackId($id);
            $pendingIds[] = $id;
            $task = $this->createTaskRecord($id);
            $this->repository->save($task);
        }

        $completedIds = [];
        for ($i = 0; $i < 2; $i++) {
            $id = '550e8400-e29b-41d4-a716-44665544'.str_pad((string) ($i + 30), 4, '0', STR_PAD_LEFT);
            $this->trackId($id);
            $completedIds[] = $id;
            $task = $this->createTaskRecord($id);
            $this->repository->save($task);
            $this->repository->moveToCompleted(new TaskIdVO($id), $task);
        }

        $this->assertEquals(3, $this->repository->countPending());
        $this->assertEquals(2, $this->repository->countCompleted());
        $this->assertEquals(0, $this->repository->countFailed());
        $this->assertEquals(5, $this->repository->count());
    }

    public function test_deletes_task_removes_all_versions(): void
    {

        $id = '550e8400-e29b-41d4-a716-446655440014';
        $this->trackId($id);

        for ($i = 0; $i < 3; $i++) {
            $task = new UniqueTaskRecord(
                id: new TaskIdVO($id),
                alias: new TaskSignatureVO('test-alias'),
                fqcn: 'TestTask',
                payload: StrictDataObject::from(['version' => $i]),
                scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(30)->toIso8601String()),
                grace_period_seconds: 86400,
                status: UniqueTaskStatus::PENDING,
                attempts: new CounterVO($i),
                max_attempts: new CounterVO(3),
            );
            $this->repository->save($task);
        }

        $versions = $this->repository->findAllVersions(new TaskIdVO($id));
        $this->assertCount(3, $versions);

        $this->repository->delete(new TaskIdVO($id));

        $versions = $this->repository->findAllVersions(new TaskIdVO($id));
        $this->assertCount(0, $versions);

        $path = $this->baseStoragePath.'/unique/pending/'.$id.'.jsonl';
        $this->assertFalse($this->fs->exists($path));
    }

    public function test_find_by_alias_returns_only_pending(): void
    {

        $alias = new TaskSignatureVO('sms-tasks');

        $id1 = '550e8400-e29b-41d4-a716-446655440015';
        $id2 = '550e8400-e29b-41d4-a716-446655440016';

        $this->trackId($id1);
        $this->trackId($id2);

        $task1 = $this->createTaskRecord($id1, 'sms-tasks', 'pending');
        $this->repository->save($task1);

        $task2 = $this->createTaskRecord($id2, 'sms-tasks', 'pending');
        $this->repository->save($task2);
        $this->repository->moveToCompleted(new TaskIdVO($id2), $task2);

        $tasks = $this->repository->findByAlias($alias);
        $this->assertCount(1, $tasks);
        $this->assertEquals($id1, $tasks->first()->id->value);
    }
}
