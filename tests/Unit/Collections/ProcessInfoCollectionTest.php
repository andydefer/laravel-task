<?php

// tests/Unit/Collections/ProcessInfoCollectionTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Collections;

use AndyDefer\Task\Collections\ProcessInfoCollection;
use AndyDefer\Task\Records\ProcessInfoRecord;
use AndyDefer\Task\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProcessInfoCollectionTest extends UnitTestCase
{
    public function test_add_and_count(): void
    {
        $collection = new ProcessInfoCollection;

        $process1 = new ProcessInfoRecord(pid: 1000, taskIdentifier: 'task-1', startedAt: time());
        $process2 = new ProcessInfoRecord(pid: 1001, taskIdentifier: 'task-2', startedAt: time());

        $collection->add($process1);
        $collection->add($process2);

        $this->assertSame(2, $collection->count());
        $this->assertFalse($collection->isEmpty());
    }

    public function test_force_kill_all(): void
    {
        $collection = new ProcessInfoCollection;

        // We can't actually test killing processes in unit tests
        // This test just verifies the method exists and doesn't throw
        $collection->forceKillAll();

        $this->addToAssertionCount(1);
    }
}
