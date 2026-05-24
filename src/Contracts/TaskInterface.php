<?php

// src/Contracts/TaskInterface.php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts;

use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;

interface TaskInterface
{
    public function getConfig(): TaskConfigRecord;

    public function execute(TaskMode $mode, TaskPayloadRecord $payload): void;
}
