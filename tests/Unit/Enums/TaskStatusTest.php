<?php

// tests/Unit/Enums/TaskStatusTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Enums;

use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Tests\UnitTestCase;

final class TaskStatusTest extends UnitTestCase
{
    public function test_values_returns_all_statuses(): void
    {
        $values = TaskStatus::values();

        $this->assertContains('pending', $values);
        $this->assertContains('running', $values);
        $this->assertContains('success', $values);
        $this->assertContains('failed', $values);
    }

    public function test_get_label_returns_correct_label(): void
    {
        $this->assertSame('Pending', TaskStatus::PENDING->getLabel());
        $this->assertSame('Running', TaskStatus::RUNNING->getLabel());
        $this->assertSame('Success', TaskStatus::SUCCESS->getLabel());
        $this->assertSame('Failed', TaskStatus::FAILED->getLabel());
    }

    public function test_is_pending_returns_true_only_for_pending(): void
    {
        $this->assertTrue(TaskStatus::PENDING->isPending());
        $this->assertFalse(TaskStatus::RUNNING->isPending());
        $this->assertFalse(TaskStatus::SUCCESS->isPending());
        $this->assertFalse(TaskStatus::FAILED->isPending());
    }

    public function test_is_running_returns_true_only_for_running(): void
    {
        $this->assertTrue(TaskStatus::RUNNING->isRunning());
        $this->assertFalse(TaskStatus::PENDING->isRunning());
        $this->assertFalse(TaskStatus::SUCCESS->isRunning());
        $this->assertFalse(TaskStatus::FAILED->isRunning());
    }

    public function test_is_success_returns_true_only_for_success(): void
    {
        $this->assertTrue(TaskStatus::SUCCESS->isSuccess());
        $this->assertFalse(TaskStatus::PENDING->isSuccess());
        $this->assertFalse(TaskStatus::RUNNING->isSuccess());
        $this->assertFalse(TaskStatus::FAILED->isSuccess());
    }

    public function test_is_failed_returns_true_only_for_failed(): void
    {
        $this->assertTrue(TaskStatus::FAILED->isFailed());
        $this->assertFalse(TaskStatus::PENDING->isFailed());
        $this->assertFalse(TaskStatus::RUNNING->isFailed());
        $this->assertFalse(TaskStatus::SUCCESS->isFailed());
    }

    public function test_from_value_returns_correct_enum(): void
    {
        $this->assertSame(TaskStatus::PENDING, TaskStatus::fromValue('pending'));
        $this->assertSame(TaskStatus::RUNNING, TaskStatus::fromValue('running'));
        $this->assertSame(TaskStatus::SUCCESS, TaskStatus::fromValue('success'));
        $this->assertSame(TaskStatus::FAILED, TaskStatus::fromValue('failed'));
    }
}
