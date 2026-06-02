<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\UnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
final class TaskRegistryServiceTest extends UnitTestCase
{
    private TaskRegistryService $registry;

    private TaskStorageService&MockObject $storage;

    private TaskValidatorService&MockObject $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = $this->createMock(TaskStorageService::class);
        $this->validator = $this->createMock(TaskValidatorService::class);
        $this->registry = new TaskRegistryService($this->storage, $this->validator);
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection;
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'registry_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );
    }

    // ==================== Validation Tests ====================

    public function test_register_throws_exception_for_invalid_task_class(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with('InvalidClass')
            ->willReturn(false);

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend AbstractTask');

        // Act
        $this->registry->register(
            taskClass: 'InvalidClass',

            payload: $payload,
        );
    }

    public function test_register_does_not_save_if_validation_fails(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')->willReturn(false);
        $this->storage->expects($this->never())->method('savePending');

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend AbstractTask');

        // Act
        $this->registry->register(
            taskClass: 'InvalidClass',

            payload: $payload,
        );
    }

    // ==================== Enforce Exact Schedule Tests ====================

    public function test_register_unique_task_with_enforce_exact_schedule(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        // Assert
        $this->storage->expects($this->once())
            ->method('savePending')
            ->with($this->callback(fn($task): bool => $task->enforceExactSchedule === true));

        // Act
        $this->registry->register(
            taskClass: TestTask::class,

            payload: $payload,
            enforceExactSchedule: true,
        );
    }

    public function test_register_unique_task_without_enforce_exact_schedule(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        // Assert
        $this->storage->expects($this->once())
            ->method('savePending')
            ->with($this->callback(fn($task): bool => $task->enforceExactSchedule === false));

        // Act
        $this->registry->register(
            taskClass: TestTask::class,

            payload: $payload,
            enforceExactSchedule: false,
        );
    }

    public function test_register_unique_task_with_default_enforce_exact_schedule(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        // Assert
        $this->storage->expects($this->once())
            ->method('savePending')
            ->with($this->callback(fn($task): bool => $task->enforceExactSchedule === false));

        // Act
        $this->registry->register(
            taskClass: TestTask::class,

            payload: $payload,
        );
    }

    // ==================== Task Properties Tests ====================

    public function test_register_creates_task_with_correct_properties(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();
        /** @var TaskRecord|null $capturedTask */
        $capturedTask = null;

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('savePending')
            ->willReturnCallback(function ($task) use (&$capturedTask): void {
                $capturedTask = $task;
            });

        // Act
        $this->registry->register(
            taskClass: TestTask::class,

            payload: $payload,
            enforceExactSchedule: true,
        );

        // Assert
        $this->assertNotNull($capturedTask);
        $this->assertSame(TestTask::class, $capturedTask->class);
        $this->assertSame($payload, $capturedTask->payload);
        $this->assertTrue($capturedTask->enforceExactSchedule);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $capturedTask->id);
    }

    public function test_register_creates_unique_task_id(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();
        /** @var array<int, string> $capturedIds */
        $capturedIds = [];

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        $this->storage->expects($this->exactly(2))
            ->method('savePending')
            ->willReturnCallback(function ($task) use (&$capturedIds): void {
                $capturedIds[] = $task->id;
            });

        // Act
        $this->registry->register(
            taskClass: TestTask::class,

            payload: $payload,
        );

        $this->registry->register(
            taskClass: TestTask::class,

            payload: $payload,
        );

        // Assert
        $this->assertCount(2, $capturedIds);
        $this->assertNotSame($capturedIds[0], $capturedIds[1]);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $capturedIds[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $capturedIds[1]);
    }

    public function test_register_creates_task_with_uuid(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();
        /** @var TaskRecord|null $capturedTask */
        $capturedTask = null;

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('savePending')
            ->willReturnCallback(function ($task) use (&$capturedTask): void {
                $capturedTask = $task;
            });

        // Act
        $this->registry->register(
            taskClass: TestTask::class,

            payload: $payload,
        );

        // Assert
        $this->assertNotNull($capturedTask);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $capturedTask->id
        );
    }

    // ==================== Validation Order Tests ====================

    public function test_register_passes_validation_before_saving(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();
        $validationCalled = false;

        $this->validator->expects($this->once())
            ->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturnCallback(function () use (&$validationCalled): bool {
                $validationCalled = true;

                return true;
            });

        $this->storage->expects($this->once())
            ->method('savePending')
            ->willReturnCallback(function () use (&$validationCalled): void {
                $this->assertTrue($validationCalled);
            });

        // Act
        $this->registry->register(
            taskClass: TestTask::class,

            payload: $payload,
        );
    }
}
