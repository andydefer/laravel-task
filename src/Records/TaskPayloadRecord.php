<?php

// src/Records/TaskPayloadRecord.php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

final class TaskPayloadRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $type,
        public readonly StrictDataObject $data,
    ) {}
}
