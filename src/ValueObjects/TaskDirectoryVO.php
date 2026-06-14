<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class TaskDirectoryVO extends AbstractValueObject
{
    public function __construct(
        public readonly string $basePath,
        public readonly TaskType $type,
    ) {}

    public function getValue(): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $this->type->value;
    }

    public function ensureExists(FileSystemInterface $fs): void
    {
        $path = $this->getValue();

        if (!$fs->isDirectory($path)) {
            $fs->makeDirectory($path, PermissionMode::DIRECTORY, true);
        }
    }

    public function filePath(TaskIdVO|TaskSignatureVO $identifier): string
    {
        return $this->getValue() . DIRECTORY_SEPARATOR . $identifier->fileName();
    }

    public function filePathWithDate(TaskIdVO $taskId, TaskDateVO $date): string
    {
        return $this->getValue() . DIRECTORY_SEPARATOR . $date->value . DIRECTORY_SEPARATOR . $taskId->fileName();
    }

    public function allFiles(FileSystemInterface $fs): array
    {
        $pattern = $this->getValue() . '/*.jsonl';
        return $fs->glob($pattern);
    }

    public function getValueObject(): string
    {
        return $this->getValue();
    }
}
