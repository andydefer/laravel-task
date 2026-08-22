<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\DomainStructures\Utils\StrictAssociative;
use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Collections\TaskFqcnVOCollection;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Handlers\OutputHandler;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes tasks in parallel using pcntl_fork().
 *
 * Creates multiple worker processes to run the tasks:process directive
 * simultaneously, aggregating results from all workers.
 */
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

    /**
     * Executes tasks in parallel with the specified configuration.
     *
     * @param  bool  $uniqueOnly  Whether to process only unique tasks
     * @param  bool  $recurringOnly  Whether to process only recurring tasks
     * @param  LimitVO|null  $limit  Maximum tasks per worker
     * @param  bool  $verbose  Whether to show detailed logs
     * @param  bool  $muted  Whether to suppress console output
     * @param  TaskFqcnVOCollection|null  $fqcns  Optional FQCN filter
     * @return array<TaskExecutionResultRecord> Results from all workers
     */
    public function execute(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $muted = false,
        ?TaskFqcnVOCollection $fqcns = null
    ): array {
        $results = [];

        $this->output->info("🚀 Starting {$this->maxWorkers} parallel workers...");

        if (! function_exists('pcntl_fork')) {
            $this->output->warning('⚠️ pcntl_fork() not available. Workers will run sequentially.');

            return $this->executeSequentially($uniqueOnly, $recurringOnly, $limit, $verbose, $muted, $fqcns);
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
                    $this->resetDatabaseConnection();

                    $result = $this->runWorker($i, $uniqueOnly, $recurringOnly, $limit, $verbose, $muted, $fqcns);

                    $data = $result !== null ? serialize($result) : 'null';
                    socket_write($pipe[1], $data, strlen($data));
                    socket_close($pipe[1]);

                    exit(0);
                } catch (Throwable $e) {
                    $errorMessage = 'error:'.$e->getMessage();
                    socket_write($pipe[1], $errorMessage, strlen($errorMessage));
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

            if (pcntl_wifexited($status)) {
                $exitCode = pcntl_wexitstatus($status);
                if ($exitCode !== 0) {
                    $this->output->error("❌ Worker {$pid} exited with code {$exitCode}");
                    socket_close($pipe);

                    continue;
                }
            } else {
                $this->output->error("❌ Worker {$pid} terminated abnormally");
                socket_close($pipe);

                continue;
            }

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
                try {
                    $result = unserialize($data);
                    if ($result instanceof TaskExecutionResultRecord) {
                        $results[] = $result;
                    }
                } catch (Throwable $e) {
                    $this->output->error('❌ Failed to unserialize result: '.$e->getMessage());
                }
            }
        }

        return $results;
    }

    /**
     * Executes workers sequentially when pcntl_fork() is not available.
     *
     * @param  bool  $uniqueOnly  Whether to process only unique tasks
     * @param  bool  $recurringOnly  Whether to process only recurring tasks
     * @param  LimitVO|null  $limit  Maximum tasks per worker
     * @param  bool  $verbose  Whether to show detailed logs
     * @param  bool  $muted  Whether to suppress console output
     * @param  TaskFqcnVOCollection|null  $fqcns  Optional FQCN filter
     * @return array<TaskExecutionResultRecord> Results from all workers
     */
    private function executeSequentially(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $muted = false,
        ?TaskFqcnVOCollection $fqcns = null
    ): array {
        $results = [];

        for ($i = 1; $i <= $this->maxWorkers; $i++) {
            try {
                $this->resetDatabaseConnection();

                $result = $this->runWorker($i, $uniqueOnly, $recurringOnly, $limit, $verbose, $muted, $fqcns);
                if ($result !== null) {
                    $results[] = $result;
                }
            } catch (Throwable $e) {
                $this->output->error("❌ Worker {$i} failed: ".$e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Runs a single worker process.
     *
     * @param  int  $workerId  The worker identifier
     * @param  bool  $uniqueOnly  Whether to process only unique tasks
     * @param  bool  $recurringOnly  Whether to process only recurring tasks
     * @param  LimitVO|null  $limit  Maximum tasks per worker
     * @param  bool  $verbose  Whether to show detailed logs
     * @param  bool  $muted  Whether to suppress console output
     * @param  TaskFqcnVOCollection|null  $fqcns  Optional FQCN filter
     * @return TaskExecutionResultRecord|null The execution result
     */
    private function runWorker(
        int $workerId,
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $muted = false,
        ?TaskFqcnVOCollection $fqcns = null
    ): ?TaskExecutionResultRecord {
        $this->output->debug("🔧 Worker {$workerId} starting...");

        $argv = ['directive', 'tasks:process'];

        if ($limit !== null) {
            $argv[] = (string) $limit->getValue();
        } else {
            $argv[] = 'infinite';
        }

        if ($fqcns !== null && $fqcns->isNotEmpty()) {
            $fqcnStrings = $fqcns->toStrings();
            $argv[] = '['.implode(', ', $fqcnStrings).']';
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

        try {
            $exitCode = $this->kernel->run($argv);

            $context = $this->kernel->getContext();

            $resultRecords = [];
            foreach ($context as $key => $value) {
                if (str_starts_with($key, 'unique-') || str_starts_with($key, 'recurring-')) {
                    if ($value instanceof TaskExecutionResultRecord) {
                        $resultRecords[] = $value;
                    }
                }
            }

            $this->output->debug("✅ Worker {$workerId} completed with exit code: ".$exitCode->value);

            if (empty($resultRecords)) {
                return null;
            }

            if (count($resultRecords) === 1) {
                return $resultRecords[0];
            }

            return $this->mergeResults($resultRecords);

        } catch (Throwable $e) {
            $this->output->error("❌ Worker {$workerId} threw exception: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Merges multiple result records into a single aggregated result.
     *
     * Combines success and failure counts by task type and aggregates errors.
     *
     * @param  array<TaskExecutionResultRecord>  $results  The results to merge
     * @return TaskExecutionResultRecord The merged result
     */
    private function mergeResults(array $results): TaskExecutionResultRecord
    {
        $totalSuccess = 0;
        $totalFailed = 0;
        $allErrors = new TaskErrorRecordCollection;
        $typeCounts = [];
        $failedCounts = [];
        $hasUnique = false;
        $hasRecurring = false;

        foreach ($results as $result) {
            $success = $result->success->getValue();
            $failed = $result->failed->getValue();

            $totalSuccess += $success;
            $totalFailed += $failed;

            foreach ($result->errors as $error) {
                $allErrors->add($error);
            }

            $type = $result->type->value;
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + $success;
            $failedCounts[$type] = ($failedCounts[$type] ?? 0) + $failed;

            if ($result->type === TaskType::UNIQUE) {
                $hasUnique = true;
            } elseif ($result->type === TaskType::RECURRING) {
                $hasRecurring = true;
            }
        }

        $now = new Iso8601DateTimeVO;
        $uuid = UuidVO::generate()->getValue();

        $type = $hasUnique ? TaskType::UNIQUE : TaskType::RECURRING;

        return TaskExecutionResultRecord::from([
            'id' => $uuid,
            'started_at' => $now,
            'ended_at' => $now,
            'duration_ms' => new MillisecondsVO(0),
            'success' => new CounterVO($totalSuccess),
            'failed' => new CounterVO($totalFailed),
            'total' => new CounterVO($totalSuccess + $totalFailed),
            'errors' => $allErrors,
            'has_failures' => $totalFailed > 0 || $allErrors->count() > 0,
            'type' => $type,
            'type_counts' => new StrictAssociative($typeCounts),
            'failed_counts' => new StrictAssociative($failedCounts),
            'has_unique' => $hasUnique,
            'has_recurring' => $hasRecurring,
        ]);
    }

    /**
     * Resets the database connection to avoid conflicts between processes.
     *
     * Purges existing connections and creates a fresh one.
     *
     * @throws Throwable If the connection reset fails
     */
    private function resetDatabaseConnection(): void
    {
        try {
            DB::purge();
            DB::reconnect();
            DB::connection()->getPdo();

            $this->output->debug('✅ Database connection reset successfully');
        } catch (Throwable $e) {
            $this->output->error('❌ Failed to reset database connection: '.$e->getMessage());
            throw $e;
        }
    }
}
