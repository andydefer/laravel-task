<?php

// tests/Unit/Services/TaskRegistryTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRegistry;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\UnitTestCase;
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

    public function test_register_throws_exception_for_invalid_task_class(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $this->validator->method('validateTaskClass')->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);

        $this->registry->register(
            taskClass: 'InvalidClass',
            mode: TaskMode::SYNC,
            payload: $payload,
        );
    }

    public function test_register_unique_task_with_enforce_exact_schedule(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $this->validator->method('validateTaskClass')->willReturn(true);

        $this->storage->expects($this->once())
            ->method('savePending')
            ->with($this->callback(function ($task) {
                return $task->enforceExactSchedule === true;
            }));

        $this->registry->register(
            taskClass: TestTask::class,
            mode: TaskMode::SYNC,
            payload: $payload,
            enforceExactSchedule: true,
        );
    }

    public function test_register_unique_task_without_enforce_exact_schedule(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $this->validator->method('validateTaskClass')->willReturn(true);

        $this->storage->expects($this->once())
            ->method('savePending')
            ->with($this->callback(function ($task) {
                return $task->enforceExactSchedule === false;
            }));

        $this->registry->register(
            taskClass: TestTask::class,
            mode: TaskMode::SYNC,
            payload: $payload,
            enforceExactSchedule: false,
        );
    }
}
