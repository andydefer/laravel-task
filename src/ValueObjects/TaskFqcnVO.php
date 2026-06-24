<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\Task\Contracts\Abstract\TaskInterface;
use InvalidArgumentException;
use ReflectionClass;

class TaskFqcnVO extends AbstractValueObject
{
    public readonly string $value;

    public function __construct(string $fqcn)
    {
        self::validate($fqcn);
        $this->value = $fqcn;
    }

    public static function validate(string $fqcn): void
    {
        if (! class_exists($fqcn)) {
            throw new InvalidArgumentException(
                sprintf('Task class "%s" does not exist.', $fqcn)
            );
        }

        if (! is_subclass_of($fqcn, TaskInterface::class)) {
            throw new InvalidArgumentException(
                sprintf('Class "%s" must implement %s', $fqcn, TaskInterface::class)
            );
        }

        $reflection = new ReflectionClass($fqcn);
        if ($reflection->isAbstract()) {
            throw new InvalidArgumentException(
                sprintf('Task class "%s" cannot be abstract.', $fqcn)
            );
        }

        if ($reflection->isInterface()) {
            throw new InvalidArgumentException(
                sprintf('Task class "%s" cannot be an interface.', $fqcn)
            );
        }

        if (! $reflection->isInstantiable()) {
            throw new InvalidArgumentException(
                sprintf('Task class "%s" is not instantiable.', $fqcn)
            );
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
