<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\ValueObjects\LimitVO;
use Throwable;

final class ParallelExecutor
{
    private int $maxWorkers;

    private Console $console;

    private DirectiveKernel $kernel;

    public function __construct(int $maxWorkers, Console $console, DirectiveKernel $kernel)
    {
        $this->maxWorkers = max(1, $maxWorkers);
        $this->console = $console;
        $this->kernel = $kernel;
    }

    public function execute(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose
    ): array {
        $results = [];

        $this->console->info("🚀 Starting {$this->maxWorkers} parallel workers...");

        for ($i = 1; $i <= $this->maxWorkers; $i++) {
            try {
                $result = $this->runWorker($i, $uniqueOnly, $recurringOnly, $limit, $verbose);

                if ($result !== null) {
                    $results[] = $result;
                }
            } catch (Throwable $e) {
                $this->console->error("❌ Worker {$i} failed: ".$e->getMessage());
            }
        }

        return $results;
    }

    private function runWorker(
        int $workerId,
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose
    ): ?TaskExecutionResultRecord {
        $this->console->logDebug("🔧 Worker {$workerId} starting...");

        $argv = ['directive', 'tasks:process'];

        if ($limit !== null) {
            $argv[] = (string) $limit->getValue();
        } else {
            $argv[] = 'infinite';
        }

        if ($uniqueOnly) {
            $argv[] = '--unique-only';
        }

        if ($recurringOnly) {
            $argv[] = '--recurring-only';
        }

        if ($verbose) {
            $argv[] = '--verbose';
        }
        $argv[] = '--mute';

        $this->kernel->getContext()->put('worker_id', $workerId);

        $exitCode = $this->kernel->run($argv);

        $context = $this->kernel->getContext();
        $result = null;

        foreach ($context as $key => $value) {
            if (str_starts_with($key, 'unique-') || str_starts_with($key, 'recurring-')) {
                if ($value instanceof TaskExecutionResultRecord) {
                    $result = $value;
                }
            }
        }

        $this->console->logDebug("✅ Worker {$workerId} completed with exit code: ".$exitCode->value);

        return $result;
    }
}
