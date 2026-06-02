<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\Records\ProcessInfoRecord;

/**
 * Collection of process information records with process management capabilities.
 *
 * Provides specialized operations for managing system processes including
 * filtering running processes, removing completed ones, and force termination.
 *
 * @extends AbstractTypedCollection<ProcessInfoRecord>
 */
final class ProcessInfoCollection extends AbstractTypedCollection
{
    /**
     * Initialize an empty process information collection.
     */
    public function __construct()
    {
        parent::__construct(ProcessInfoRecord::class);
    }
}
