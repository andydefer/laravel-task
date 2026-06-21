<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use InvalidArgumentException;

final class TaskRegistryServiceTest extends IntegrationTestCase
{
    private TaskRegistryService $registry;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->app->make(TaskRegistryService::class);
        $this->storagePath = config('task.storage_path', storage_path('tasks'));
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection;
        $payloadCollection->add(new StrictDataObject([
            'test_data' => 'registry_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            data: new StrictDataObject([
                'test_data' => 'registry_test',
            ]),
        );
    }

    // ==================== REGISTER TESTS ====================

    public function test_register_throws_exception_for_invalid_task_class(): void
    {
        $payload = $this->createTaskPayload();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend AbstractTask');

        $this->registry->register(
            taskClass: 'InvalidClass',
            payload: $payload,
        );
    }

    public function test_register_unique_task_success(): void
    {
        $payload = $this->createTaskPayload();

        $result = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
        );

        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $result);
    }

    public function test_register_unique_task_with_override_config(): void
    {
        $payload = $this->createTaskPayload();

        $overrideConfig = new TaskConfigRecord(
            signature: new TaskSignatureVO('override-task'),
            description: 'Override task config',
            delay_seconds: new CounterVO(0),
            max_attempts: new CounterVO(5),
            start_at: null,
            end_at: null,
        );

        $result = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $overrideConfig,
        );

        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $result);
    }

    public function test_register_recurring_task_success(): void
    {
        $payload = $this->createTaskPayload();

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-test'),
            description: 'Recurring test task',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $result = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        $this->assertSame('recurring-test', $result);
    }

    public function test_register_recurring_task_already_exists_throws_exception(): void
    {
        $payload = $this->createTaskPayload();

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-test'),
            description: 'Recurring test task',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        // Première inscription
        $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        // Deuxième inscription - doit échouer
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Recurring task 'recurring-test' already exists");

        $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );
    }

    public function test_register_creates_unique_task_id(): void
    {
        $payload = $this->createTaskPayload();

        $id1 = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
        );

        $id2 = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
        );

        $this->assertNotSame($id1, $id2);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $id1);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $id2);
    }

    // ==================== FILE EXISTENCE TESTS ====================

    public function test_register_unique_task_creates_file_in_pending_directory(): void
    {
        $payload = $this->createTaskPayload();

        $taskId = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
        );

        // ✅ Vérifier que le fichier existe au bon endroit
        $expectedPath = $this->storagePath.'/pending/'.$taskId.'.jsonl';
        $this->assertFileExists($expectedPath);

        // ✅ Vérifier que le contenu est valide
        $content = file_get_contents($expectedPath);
        $data = json_decode($content, true);
        $this->assertEquals($taskId, $data['id']);
        $this->assertEquals('pending', $data['status']);
        $this->assertEquals(TestTask::class, $data['class']);
    }

    public function test_register_unique_task_with_override_config_creates_file_with_correct_start_at(): void
    {
        $payload = $this->createTaskPayload();

        $futureDate = (new \DateTime)->modify('+1 hour')->format('Y-m-d\TH:i:sP');

        $overrideConfig = new TaskConfigRecord(
            signature: new TaskSignatureVO('override-task'),
            description: 'Override task config',
            delay_seconds: new CounterVO(0),
            max_attempts: new CounterVO(5),
            start_at: new Iso8601DateTimeVO($futureDate),
            end_at: null,
        );

        $taskId = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $overrideConfig,
        );

        // ✅ Vérifier que start_at est bien dans le fichier
        $expectedPath = $this->storagePath.'/pending/'.$taskId.'.jsonl';
        $this->assertFileExists($expectedPath);
        $content = file_get_contents($expectedPath);
        $data = json_decode($content, true);
        $this->assertEquals($futureDate, $data['start_at']);
        $this->assertEquals(5, $data['max_attempts']);
    }

    public function test_register_recurring_task_creates_file_in_recurring_directory(): void
    {
        $payload = $this->createTaskPayload();

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-test'),
            description: 'Recurring test task',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $signature = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        // ✅ Vérifier que le fichier existe au bon endroit
        $expectedPath = $this->storagePath.'/recurring/'.$signature.'.jsonl';
        $this->assertFileExists($expectedPath);

        // ✅ Vérifier le contenu
        $content = file_get_contents($expectedPath);
        $data = json_decode($content, true);
        $this->assertEquals($signature, $data['signature']);
        $this->assertEquals(300, $data['delay_seconds']);
        $this->assertEquals(TestTask::class, $data['class']);
    }

    public function test_register_recurring_task_with_start_at_creates_file_with_correct_start_at(): void
    {
        $payload = $this->createTaskPayload();

        $futureDate = (new \DateTime)->modify('+1 hour')->format('Y-m-d\TH:i:sP');

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-with-start'),
            description: 'Recurring with start',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: new Iso8601DateTimeVO($futureDate),
            end_at: null,
        );

        $signature = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        // ✅ Vérifier que start_at est bien dans le fichier
        $expectedPath = $this->storagePath.'/recurring/'.$signature.'.jsonl';
        $this->assertFileExists($expectedPath);
        $content = file_get_contents($expectedPath);
        $data = json_decode($content, true);
        $this->assertEquals($futureDate, $data['start_at']);
        $this->assertEquals($futureDate, $data['next_run_at']);
    }

    public function test_task_file_contains_correct_payload(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            data: new StrictDataObject([
                'test_data' => 'registry_test',
                'nested' => [
                    'key' => 'value',
                    'array' => [1, 2, 3],
                ],
            ]),
        );

        $taskId = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
        );

        // ✅ Vérifier que le payload est bien stocké
        $expectedPath = $this->storagePath.'/pending/'.$taskId.'.jsonl';
        $content = file_get_contents($expectedPath);
        $data = json_decode($content, true);

        $this->assertEquals('test', $data['payload']['type']);
        $this->assertEquals('registry_test', $data['payload']['data']['test_data']);
        $this->assertEquals('value', $data['payload']['data']['nested']['key']);
        $this->assertEquals([1, 2, 3], $data['payload']['data']['nested']['array']);
    }

    public function test_recurring_task_file_contains_correct_payload(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            data: new StrictDataObject([
                'test_data' => 'registry_test',
                'nested' => [
                    'key' => 'value',
                    'array' => [1, 2, 3],
                ],
            ]),
        );

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-payload-test'),
            description: 'Recurring payload test',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $signature = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        // ✅ Vérifier que le payload est bien stocké
        $expectedPath = $this->storagePath.'/recurring/'.$signature.'.jsonl';
        $content = file_get_contents($expectedPath);
        $data = json_decode($content, true);

        $this->assertEquals('test', $data['payload']['type']);
        $this->assertEquals('registry_test', $data['payload']['data']['test_data']);
        $this->assertEquals('value', $data['payload']['data']['nested']['key']);
        $this->assertEquals([1, 2, 3], $data['payload']['data']['nested']['array']);
    }

    // ==================== UNREGISTER TESTS ====================

    public function test_unregister_recurring(): void
    {
        $payload = $this->createTaskPayload();
        $signature = new TaskSignatureVO('recurring-to-delete');

        $config = new TaskConfigRecord(
            signature: $signature,
            description: 'Recurring to delete',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        $this->registry->unregisterRecurring($signature);

        // Réinscription après suppression - doit réussir
        $result = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        $this->assertSame('recurring-to-delete', $result);
    }

    public function test_unregister_task_removes_unique_task(): void
    {
        $payload = $this->createTaskPayload();

        $taskId = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
        );

        // Vérifier que la tâche existe
        $taskIdVO = new TaskIdVO($taskId);
        $task = $this->app->make(TaskRepositoryInterface::class)->find($taskIdVO);
        $this->assertNotNull($task);

        // Supprimer la tâche
        $this->registry->unregisterTask($taskIdVO);

        // Vérifier que la tâche a été supprimée
        $deletedTask = $this->app->make(TaskRepositoryInterface::class)->find($taskIdVO);
        $this->assertNull($deletedTask);
    }

    public function test_unregister_task_removes_file_from_pending_directory(): void
    {
        $payload = $this->createTaskPayload();

        $taskId = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
        );

        $expectedPath = $this->storagePath.'/pending/'.$taskId.'.jsonl';
        $this->assertFileExists($expectedPath);

        // Supprimer la tâche
        $this->registry->unregisterTask(new TaskIdVO($taskId));

        // ✅ Vérifier que le fichier a été supprimé
        $this->assertFileDoesNotExist($expectedPath);
    }

    public function test_unregister_recurring_removes_file_from_recurring_directory(): void
    {
        $payload = $this->createTaskPayload();
        $signature = new TaskSignatureVO('recurring-to-delete-file');

        $config = new TaskConfigRecord(
            signature: $signature,
            description: 'Recurring to delete file',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        $expectedPath = $this->storagePath.'/recurring/'.$signature->value.'.jsonl';
        $this->assertFileExists($expectedPath);

        // Supprimer la tâche récurrente
        $this->registry->unregisterRecurring($signature);

        // ✅ Vérifier que le fichier a été supprimé
        $this->assertFileDoesNotExist($expectedPath);
    }

    public function test_unregister_task_throws_exception_when_unique_task_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unique task not found: 00000000-0000-0000-0000-000000000000');

        $taskIdVO = new TaskIdVO('00000000-0000-0000-0000-000000000000');
        $this->registry->unregisterTask($taskIdVO);
    }

    public function test_unregister_recurring_removes_recurring_task(): void
    {
        $payload = $this->createTaskPayload();
        $signature = new TaskSignatureVO('recurring-to-delete-v2');

        $config = new TaskConfigRecord(
            signature: $signature,
            description: 'Recurring to delete v2',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        // Enregistrer la tâche récurrente
        $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        // Vérifier qu'elle existe
        $found = $this->app->make(RecurringTaskRepositoryInterface::class)->find($signature);
        $this->assertNotNull($found);

        // Supprimer la tâche récurrente
        $this->registry->unregisterRecurring($signature);

        // Vérifier qu'elle a été supprimée
        $deleted = $this->app->make(RecurringTaskRepositoryInterface::class)->find($signature);
        $this->assertNull($deleted);
    }

    public function test_unregister_with_auto_detection_for_unique_task(): void
    {
        $payload = $this->createTaskPayload();

        $taskId = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
        );

        // Vérifier que la tâche existe
        $taskIdVO = new TaskIdVO($taskId);
        $task = $this->app->make(TaskRepositoryInterface::class)->find($taskIdVO);
        $this->assertNotNull($task);

        // Suppression auto-détectée (UUID → tâche unique)
        $this->registry->unregister($taskId);

        // Vérifier que la tâche a été supprimée
        $deletedTask = $this->app->make(TaskRepositoryInterface::class)->find($taskIdVO);
        $this->assertNull($deletedTask);
    }

    public function test_unregister_with_auto_detection_for_recurring_task(): void
    {
        $payload = $this->createTaskPayload();
        $signature = 'auto-detection-recurring';

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO($signature),
            description: 'Auto detection recurring',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        // Enregistrer la tâche récurrente
        $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        // Vérifier qu'elle existe
        $signatureVO = new TaskSignatureVO($signature);
        $found = $this->app->make(RecurringTaskRepositoryInterface::class)->find($signatureVO);
        $this->assertNotNull($found);

        // Suppression auto-détectée (non-UUID → tâche récurrente)
        $this->registry->unregister($signature);

        // Vérifier qu'elle a été supprimée
        $deleted = $this->app->make(RecurringTaskRepositoryInterface::class)->find($signatureVO);
        $this->assertNull($deleted);
    }

    public function test_unregister_with_auto_detection_removes_file_for_unique_task(): void
    {
        $payload = $this->createTaskPayload();

        $taskId = $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
        );

        $expectedPath = $this->storagePath.'/pending/'.$taskId.'.jsonl';
        $this->assertFileExists($expectedPath);

        // Suppression auto-détectée
        $this->registry->unregister($taskId);

        // ✅ Vérifier que le fichier a été supprimé
        $this->assertFileDoesNotExist($expectedPath);
    }

    public function test_unregister_with_auto_detection_removes_file_for_recurring_task(): void
    {
        $payload = $this->createTaskPayload();
        $signature = 'auto-detection-recurring-file';

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO($signature),
            description: 'Auto detection recurring file',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );

        $expectedPath = $this->storagePath.'/recurring/'.$signature.'.jsonl';
        $this->assertFileExists($expectedPath);

        // Suppression auto-détectée
        $this->registry->unregister($signature);

        // ✅ Vérifier que le fichier a été supprimé
        $this->assertFileDoesNotExist($expectedPath);
    }

    public function test_unregister_with_invalid_identifier_format_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task signature: INVALID_UUID');

        $this->registry->unregister('INVALID_UUID');
    }

    public function test_unregister_with_nonexistent_unique_task_throws_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unique task not found: 550e8400-e29b-41d4-a716-446655449999');

        $this->registry->unregister('550e8400-e29b-41d4-a716-446655449999');
    }
}
