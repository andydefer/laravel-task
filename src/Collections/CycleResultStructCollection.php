<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Structs\CycleResultStruct;

/**
 * @extends AbstractTypedCollection<CycleResultStruct>
 */
final class CycleResultStructCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(CycleResultStruct::class);
    }
}
