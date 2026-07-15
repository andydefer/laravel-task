<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\Task\Handlers\OutputHandler;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\ValueObjects\LimitVO;
use Throwable;

final class ParallelExecutor
{
    private int $maxWorkers;

    private DirectiveKernel $kernel;

    private OutputHandler $output;

    public function __construct(
        int $maxWorkers,
        DirectiveKernel $kernel,
        OutputHandler $output
    ) {
        $this->maxWorkers = max(1, $maxWorkers);
        $this->kernel = $kernel;
        $this->output = $output;
    }

    public function execute(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $muted = false
    ): array {
        $results = [];

        $this->output->info("🚀 Starting {$this->maxWorkers} parallel workers...");

        if (! function_exists('pcntl_fork')) {
            $this->output->warning('⚠️ pcntl_fork() not available. Workers will run sequentially.');

            return $this->executeSequentially($uniqueOnly, $recurringOnly, $limit, $verbose, $muted);
        }

        $pids = [];
        $pipes = [];

        for ($i = 1; $i <= $this->maxWorkers; $i++) {
            $pipe = [];

            if (socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pipe) === false) {
                $this->output->error("❌ Failed to create socket pair for worker {$i}");

                continue;
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->output->error("❌ Failed to fork worker {$i}");

                continue;
            }

            if ($pid === 0) {
                socket_close($pipe[0]);

                try {
                    $result = $this->runWorker($i, $uniqueOnly, $recurringOnly, $limit, $verbose, $muted);

                    $data = $result !== null ? serialize($result) : 'null';
                    socket_write($pipe[1], $data, strlen($data));
                    socket_close($pipe[1]);

                    exit(0);
                } catch (Throwable $e) {
                    socket_write($pipe[1], 'error:'.$e->getMessage());
                    socket_close($pipe[1]);
                    exit(1);
                }
            } else {
                socket_close($pipe[1]);
                $pids[$pid] = $pipe[0];
            }
        }

        foreach ($pids as $pid => $pipe) {
            $status = null;
            pcntl_waitpid($pid, $status, 0);

            $data = '';
            while ($buffer = socket_read($pipe, 1024)) {
                $data .= $buffer;
            }
            socket_close($pipe);

            if (str_starts_with($data, 'error:')) {
                $this->output->error('❌ Worker failed: '.substr($data, 6));

                continue;
            }

            if ($data !== 'null' && $data !== '') {
                $result = unserialize($data);
                if ($result instanceof TaskExecutionResultRecord) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    private function executeSequentially(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $muted = false
    ): array {
        $results = [];

        for ($i = 1; $i <= $this->maxWorkers; $i++) {
            try {
                $result = $this->runWorker($i, $uniqueOnly, $recurringOnly, $limit, $verbose, $muted);
                if ($result !== null) {
                    $results[] = $result;
                }
            } catch (Throwable $e) {
                $this->output->error("❌ Worker {$i} failed: ".$e->getMessage());
            }
        }

        return $results;
    }

    private function runWorker(
        int $workerId,
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $muted = false
    ): ?TaskExecutionResultRecord {
        $this->output->debug("🔧 Worker {$workerId} starting...");

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

        $this->output->debug("✅ Worker {$workerId} completed with exit code: ".$exitCode->value);

        return $result;
    }
}
