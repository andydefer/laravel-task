<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskRegistry;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\UnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
final class TaskRegistryTest extends UnitTestCase
{
    private TaskRegistry $registry;

    private TaskStorage&MockObject $storage;

    private TaskValidator&MockObject $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = $this->createMock(TaskStorage::class);
        $this->validator = $this->createMock(TaskValidator::class);
        $this->registry = new TaskRegistry($this->storage, $this->validator);
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection();
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'registry_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );
    }

    public function test_register_throws_exception_for_invalid_task_class(): void
    {
        // Arrange: Create payload and configure validator to return false
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with('InvalidClass')
            ->willReturn(false);

        // Assert: Exception is expected with the actual message from TaskRegistry
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend AbstractTask');

        // Act: Attempt to register invalid task class
        $this->registry->register(
            taskClass: 'InvalidClass',
            mode: TaskMode::SYNC,
            payload: $payload,
        );
    }

    public function test_register_unique_task_with_enforce_exact_schedule(): void
    {
        // Arrange: Create payload and configure validator to return true
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        // Assert: Expect savePending to be called with enforceExactSchedule = true
        $this->storage->expects($this->once())
            ->method('savePending')
            ->with($this->callback(function ($task) {
                return $task->enforceExactSchedule === true;
            }));

        // Act: Register a task with enforce exact schedule
        $this->registry->register(
            taskClass: TestTask::class,
            mode: TaskMode::SYNC,
            payload: $payload,
            enforceExactSchedule: true,
        );
    }

    public function test_register_unique_task_without_enforce_exact_schedule(): void
    {
        // Arrange: Create payload and configure validator to return true
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        // Assert: Expect savePending to be called with enforceExactSchedule = false
        $this->storage->expects($this->once())
            ->method('savePending')
            ->with($this->callback(function ($task) {
                return $task->enforceExactSchedule === false;
            }));

        // Act: Register a task without enforce exact schedule
        $this->registry->register(
            taskClass: TestTask::class,
            mode: TaskMode::SYNC,
            payload: $payload,
            enforceExactSchedule: false,
        );
    }

    public function test_register_unique_task_with_default_enforce_exact_schedule(): void
    {
        // Arrange: Create payload and configure validator to return true
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        // Assert: Expect savePending to be called with enforceExactSchedule = false (default)
        $this->storage->expects($this->once())
            ->method('savePending')
            ->with($this->callback(function ($task) {
                return $task->enforceExactSchedule === false;
            }));

        // Act: Register a task without specifying enforceExactSchedule (uses default)
        $this->registry->register(
            taskClass: TestTask::class,
            mode: TaskMode::SYNC,
            payload: $payload,
        );
    }

    public function test_register_creates_task_with_correct_properties(): void
    {
        // Arrange: Create payload and configure validator to return true
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        /**
         * @var TaskRecord|null $capturedTask
         */
        $capturedTask = null;

        $this->storage->expects($this->once())
            ->method('savePending')
            ->willReturnCallback(function ($task) use (&$capturedTask) {
                $capturedTask = $task;
            });

        // Act: Register a task with enforce exact schedule
        $this->registry->register(
            taskClass: TestTask::class,
            mode: TaskMode::SYNC,
            payload: $payload,
            enforceExactSchedule: true,
        );

        // Assert: Task has correct properties
        $this->assertNotNull($capturedTask, 'Task was not captured - savePending may not have been called');
        $this->assertSame(TestTask::class, $capturedTask->class);
        $this->assertSame(TaskMode::SYNC, $capturedTask->mode);
        $this->assertSame($payload, $capturedTask->payload);
        $this->assertTrue($capturedTask->enforceExactSchedule);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $capturedTask->id);
    }

    public function test_register_creates_unique_task_id(): void
    {
        // Arrange: Create payload and configure validator to return true
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        /**
         * @var array<int, string> $capturedIds
         */
        $capturedIds = [];

        $this->storage->expects($this->exactly(2))
            ->method('savePending')
            ->willReturnCallback(function ($task) use (&$capturedIds) {
                $capturedIds[] = $task->id;
            });

        // Act: Register two tasks
        $this->registry->register(
            taskClass: TestTask::class,
            mode: TaskMode::SYNC,
            payload: $payload,
        );

        $this->registry->register(
            taskClass: TestTask::class,
            mode: TaskMode::SYNC,
            payload: $payload,
        );

        // Assert: Both tasks have different IDs
        $this->assertCount(2, $capturedIds);
        $this->assertNotSame($capturedIds[0], $capturedIds[1]);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $capturedIds[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $capturedIds[1]);
    }

    public function test_register_passes_validation_before_saving(): void
    {
        // Arrange: Create payload and configure validator
        $payload = $this->createTaskPayload();

        /**
         * @var bool $validationCalled
         */
        $validationCalled = false;

        $this->validator->expects($this->once())
            ->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturnCallback(function () use (&$validationCalled) {
                $validationCalled = true;

                return true;
            });

        $this->storage->expects($this->once())
            ->method('savePending')
            ->willReturnCallback(function () use (&$validationCalled) {
                // Assert: Validation was called before save
                $this->assertTrue($validationCalled);
            });

        // Act: Register a task
        $this->registry->register(
            taskClass: TestTask::class,
            mode: TaskMode::SYNC,
            payload: $payload,
        );
    }

    public function test_register_does_not_save_if_validation_fails(): void
    {
        // Arrange: Create payload and configure validator to return false
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->willReturn(false);

        $this->storage->expects($this->never())
            ->method('savePending');

        // Assert: Exception is expected with the actual message
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend AbstractTask');

        // Act: Attempt to register invalid task
        $this->registry->register(
            taskClass: 'InvalidClass',
            mode: TaskMode::SYNC,
            payload: $payload,
        );
    }

    public function test_register_creates_task_with_uuid(): void
    {
        // Arrange: Create payload and configure validator
        $payload = $this->createTaskPayload();

        $this->validator->method('validateTaskClass')
            ->with(TestTask::class)
            ->willReturn(true);

        /**
         * @var TaskRecord|null $capturedTask
         */
        $capturedTask = null;

        $this->storage->expects($this->once())
            ->method('savePending')
            ->willReturnCallback(function ($task) use (&$capturedTask) {
                $capturedTask = $task;
            });

        // Act: Register a task
        $this->registry->register(
            taskClass: TestTask::class,
            mode: TaskMode::SYNC,
            payload: $payload,
        );

        // Assert: Task ID is a valid UUID
        $this->assertNotNull($capturedTask);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $capturedTask->id
        );
    }
}
