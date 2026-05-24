<?php

// tests/Unit/Enums/TaskModeTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Enums;

use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Tests\UnitTestCase;

final class TaskModeTest extends UnitTestCase
{
    public function test_values_returns_all_modes(): void
    {
        $values = TaskMode::values();

        $this->assertContains('sync', $values);
        $this->assertContains('defer', $values);
    }

    public function test_get_label_returns_correct_label(): void
    {
        $this->assertSame('Synchronous', TaskMode::SYNC->getLabel());
        $this->assertSame('Deferred', TaskMode::DEFER->getLabel());
    }

    public function test_is_sync_returns_true_only_for_sync(): void
    {
        $this->assertTrue(TaskMode::SYNC->isSync());
        $this->assertFalse(TaskMode::DEFER->isSync());
    }

    public function test_is_defer_returns_true_only_for_defer(): void
    {
        $this->assertTrue(TaskMode::DEFER->isDefer());
        $this->assertFalse(TaskMode::SYNC->isDefer());
    }

    public function test_from_value_returns_correct_enum(): void
    {
        $this->assertSame(TaskMode::SYNC, TaskMode::fromValue('sync'));
        $this->assertSame(TaskMode::DEFER, TaskMode::fromValue('defer'));
    }

    public function test_from_value_returns_null_for_invalid(): void
    {
        $this->assertNull(TaskMode::fromValue('invalid'));
    }
}
