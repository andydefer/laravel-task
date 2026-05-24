<?php

// src/Records/LogContextRecord.php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Records\AbstractRecord;

final class LogContextRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $event,
        public readonly MixedPayloadCollection $context,
    ) {}
}
