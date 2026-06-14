<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use InvalidArgumentException;

final class TaskRegistryServiceTest extends IntegrationTestCase
{
    private TaskRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->app->make(TaskRegistryService::class);
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
}
