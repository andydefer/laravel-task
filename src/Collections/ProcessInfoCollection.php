<?php

// src/Collections/ProcessInfoCollection.php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\Records\Collections\TypedCollection;
use AndyDefer\Task\Records\ProcessInfoRecord;

final class ProcessInfoCollection extends TypedCollection
{
    public function __construct()
    {
        parent::__construct(ProcessInfoRecord::class);
    }

    public function findRunning(): self
    {
        return $this->filter(fn (ProcessInfoRecord $process) => $this->isRunning($process->pid));
    }

    public function removeCompleted(): self
    {
        $remaining = new self;
        foreach ($this as $process) {
            if ($this->isRunning($process->pid)) {
                $remaining->add($process);
            }
        }

        return $remaining;
    }

    private function isRunning(int $pid): bool
    {
        $status = null;
        $res = pcntl_waitpid($pid, $status, WNOHANG);

        return $res !== $pid;
    }

    public function forceKillAll(): void
    {
        foreach ($this as $process) {
            posix_kill($process->pid, SIGKILL);
        }
    }
}
