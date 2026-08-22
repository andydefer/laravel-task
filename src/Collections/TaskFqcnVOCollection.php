<?php

declare(strict_types=1);

namespace AndyDefer\Task\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;

/**
 * Collection of TaskFqcnVO objects.
 *
 * Provides type-safe operations for managing lists of task FQCNs.
 *
 * @extends AbstractTypedCollection<TaskFqcnVO>
 */
final class TaskFqcnVOCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(TaskFqcnVO::class);
    }

    /**
     * Creates a collection from an array of FQCN strings.
     *
     * @param  array<string>  $fqcns
     */
    public static function fromStrings(array $fqcns): self
    {
        $collection = new self;

        foreach ($fqcns as $fqcn) {
            $collection->add(new TaskFqcnVO($fqcn));
        }

        return $collection;
    }

    /**
     * Converts the collection to an array of FQCN strings.
     *
     * @return array<string>
     */
    public function toStrings(): array
    {
        return $this->map(fn (TaskFqcnVO $vo) => $vo->getValue())->toArray();
    }

    /**
     * Checks if the collection contains a specific FQCN.
     */
    public function containsFqcn(string $fqcn): bool
    {
        foreach ($this->items as $item) {
            if ($item->getValue() === $fqcn) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filters the collection by a partial FQCN match.
     */
    public function filterByPartialFqcn(string $partial): self
    {
        $filtered = new self;

        foreach ($this->items as $item) {
            if (str_contains($item->getValue(), $partial)) {
                $filtered->add($item);
            }
        }

        return $filtered;
    }

    /**
     * Filters the collection by a namespace prefix.
     */
    public function filterByNamespace(string $namespace): self
    {
        $namespace = trim($namespace, '\\');

        $filtered = new self;

        foreach ($this->items as $item) {
            if (str_starts_with($item->getValue(), $namespace)) {
                $filtered->add($item);
            }
        }

        return $filtered;
    }
}
