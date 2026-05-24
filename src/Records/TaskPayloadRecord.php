<?php

// src/Records/TaskPayloadRecord.php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Records\AbstractRecord;

final class TaskPayloadRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $type,
        public readonly MixedPayloadCollection $payload,
    ) {}
}
