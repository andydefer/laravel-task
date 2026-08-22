<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Collections\TaskExecutionResultRecordCollection;
use AndyDefer\Task\Collections\TaskFqcnVOCollection;
use AndyDefer\Task\Handlers\OutputHandler;
use AndyDefer\Task\Handlers\SignalHandler;
use AndyDefer\Task\Helpers\JsonlResultHelper;
use AndyDefer\Task\Helpers\SessionHelper;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\Services\Watchs\ParallelExecutor;
use AndyDefer\Task\Services\Watchs\ResultAggregator;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use RuntimeException;
use Throwable;

/**
 * Directive that continuously watches and processes tasks in a loop.
 *
 * Runs task processing cycles at a configurable interval for a specified duration.
 * Supports parallel execution with multiple workers and filtering by task type.
 *
 * @example
 * php artisan tasks:watch 10 300 100 4 --verbose
 */
final class TasksWatchDirective extends AbstractDirective
{
    private const MIN_INTERVAL_SECONDS = 2;

    private OutputHandler $output;

    private SignalHandler $signalHandler;

    private CycleCalculator $cycleCalculator;

    private ParallelExecutor $parallelExecutor;

    private ResultAggregator $aggregator;

    /**
     * {@inheritDoc}
     */
    public function getSignature(): string
    {
        return 'tasks:watch 
                    {interval=60}#"Interval between cycles in seconds (minimum 2s)" 
                    {duration=?}#"Total execution duration in seconds (unlimited if omitted)" 
                    {limit=100}#"Maximum tasks to process per cycle" 
                    {parallel=?}#"Number of parallel workers (1 by default)" 
                    {fqcnNames*}#"List of FQCNs to process (e.g. [App.Tasks.SomeTask, App.Tasks.OtherTask])"
                    {--unique-only}#"Process only unique tasks" 
                    {--recurring-only}#"Process only recurring tasks" 
                    {--verbose}#"Show detailed execution logs" 
                    {--mute}#"Suppress all console output"';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Watch and process tasks in a continuous loop with configurable interval (in seconds, min 2) and duration. Use --parallel=N for parallel execution with N workers. Use --mute to suppress all console output.';
    }

    /**
     * {@inheritDoc}
     */
    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from(['task-watch', 'tw']);
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(): ExitCode
    {
        $sessionId = SessionHelper::generate();
        JsonlResultHelper::init($sessionId);

        try {
            $this->boot();

            $this->output->info('👀 Starting task watch...');
            $this->output->debug("Session ID: {$sessionId}");
            $this->displayStartMessage();

            $this->signalHandler->install();

            $hasFailures = false;
            $cycleNumber = 0;

            while ($this->cycleCalculator->shouldContinue($cycleNumber, $this->signalHandler->shouldStop())) {
                $this->signalHandler->dispatch();

                if ($this->signalHandler->shouldStop()) {
                    break;
                }

                $cycleNumber++;
                $cycleStartTime = microtime(true);

                $this->output->line();
                $this->output->line(sprintf('🔄 Cycle #%d', $cycleNumber));

                $this->aggregator->startNewCycle();
                $cycleResults = $this->executeCycle();

                if (! empty($cycleResults)) {
                    $this->aggregator->addResults($cycleResults);
                }

                $this->displayCycleSummary($cycleNumber);
                $this->displayGlobalProgress();
                $this->displayRemainingTasks();

                $elapsedTime = microtime(true) - $cycleStartTime;
                $intervalSeconds = $this->getInterval()->getValue();
                $waitTime = max(0, $intervalSeconds - $elapsedTime);

                if ($waitTime > 0 && $this->cycleCalculator->shouldContinue($cycleNumber, $this->signalHandler->shouldStop())) {
                    $this->waitWithSignals(new DurationVO($waitTime));
                }

                if ($this->aggregator->hasFailures()) {
                    $hasFailures = true;
                }
            }

            $this->displayFinalSummary();
            $this->displayFinalRemaining();

            return $hasFailures ? ExitCode::FAILURE : ExitCode::SUCCESS;

        } catch (Throwable $e) {
            $this->getKernel()->addProblem(
                'tasks_watch_error',
                'Failed to watch tasks',
                $e->getMessage(),
                ['exception' => get_class($e)]
            );

            if (isset($this->output)) {
                $this->output->error('❌ Error: '.$e->getMessage());
            }

            return ExitCode::RUNTIME_ERROR;
        } finally {
            SessionHelper::delete();
        }
    }

    /**
     * Initialises the directive dependencies and services.
     *
     * @throws RuntimeException If the Laravel container or kernel is unavailable
     */
    private function boot(): void
    {
        $app = $this->getApplication();

        if ($app === null) {
            throw new RuntimeException('Laravel container is not available');
        }

        $console = $app->make(Console::class);
        $logger = $app->make(LoggerInterface::class);

        $isMuted = $this->isFlagActive('mute');
        $isVerbose = $this->isFlagActive('verbose');

        $this->output = new OutputHandler($console, $logger, $isMuted, $isVerbose);

        $interval = $this->getInterval();
        $duration = $this->getDuration();
        $kernel = $this->getKernel();

        if ($kernel === null) {
            throw new RuntimeException('Kernel is not available');
        }

        if ($duration !== null && $interval->getValue() >= $duration->getValue()) {
            throw new RuntimeException(
                sprintf(
                    'Interval (%ds) must be less than duration (%ds)',
                    (int) $interval->getValue(),
                    (int) $duration->getValue()
                )
            );
        }

        $this->signalHandler = new SignalHandler($console);
        $this->cycleCalculator = new CycleCalculator($interval, $duration);
        $this->parallelExecutor = new ParallelExecutor(
            $this->getParallelWorkers(),
            $kernel,
            $this->output
        );
        $this->aggregator = new ResultAggregator;
    }

    /**
     * Executes a single cycle of task processing.
     *
     * @return TaskExecutionResultRecordCollection The execution results
     */
    private function executeCycle(): TaskExecutionResultRecordCollection
    {
        $uniqueOnly = $this->isFlagActive('unique-only');
        $recurringOnly = $this->isFlagActive('recurring-only');
        $verbose = $this->isFlagActive('verbose');
        $limit = $this->getLimit();
        $fqcns = $this->getFqcnFilters();

        return $this->parallelExecutor->execute(
            uniqueOnly: $uniqueOnly,
            recurringOnly: $recurringOnly,
            limit: $limit,
            verbose: $verbose,
            muted: $this->output->isMuted(),
            fqcns: $fqcns
        );
    }

    /**
     * Gets the FQCN filters from the variadic argument.
     *
     * Converts dot notation (App.Tasks.SomeTask) to PHP namespace notation.
     *
     * @return TaskFqcnVOCollection The FQCN filters
     */
    private function getFqcnFilters(): TaskFqcnVOCollection
    {
        $fqcnNames = $this->getVariadic('fqcnNames');

        if (empty($fqcnNames)) {
            return new TaskFqcnVOCollection;
        }

        $cleanedFqcns = array_map(function ($fqcn) {
            return trim(str_replace('.', '\\', $fqcn), '\\');
        }, $fqcnNames);

        return TaskFqcnVOCollection::from($cleanedFqcns);
    }

    /**
     * Displays the summary for a completed cycle.
     */
    private function displayCycleSummary(int $cycleNumber): void
    {
        if ($this->output->isMuted()) {
            return;
        }

        $success = $this->aggregator->getCycleSuccess($cycleNumber)->getValue();
        $failed = $this->aggregator->getCycleFailed($cycleNumber)->getValue();
        $errors = $this->aggregator->getCycleErrors($cycleNumber)->getValue();

        $total = $success + $failed;

        if ($total === 0) {
            $this->output->line("  Cycle #{$cycleNumber}: No tasks processed");

            return;
        }

        $status = $failed > 0 || $errors > 0 ? '⚠️' : '✅';
        $this->output->line(
            sprintf(
                '  %s Cycle #%d: %d tasks (%d success, %d failed, %d errors)',
                $status,
                $cycleNumber,
                $total,
                $success,
                $failed,
                $errors
            )
        );

        if ($this->output->isVerbose()) {
            $uniqueSuccess = $this->aggregator->getCycleUniqueSuccess($cycleNumber)->getValue();
            $uniqueFailed = $this->aggregator->getCycleUniqueFailed($cycleNumber)->getValue();
            $recurringSuccess = $this->aggregator->getCycleRecurringSuccess($cycleNumber)->getValue();
            $recurringFailed = $this->aggregator->getCycleRecurringFailed($cycleNumber)->getValue();

            $uniqueTotal = $uniqueSuccess + $uniqueFailed;
            $recurringTotal = $recurringSuccess + $recurringFailed;

            $this->output->line(
                sprintf(
                    '    Unique: %d/%d | Recurring: %d/%d',
                    $uniqueSuccess,
                    $uniqueTotal > 0 ? $uniqueTotal : 0,
                    $recurringSuccess,
                    $recurringTotal > 0 ? $recurringTotal : 0
                )
            );
        }
    }

    /**
     * Displays the global progress across all cycles.
     */
    private function displayGlobalProgress(): void
    {
        if ($this->output->isMuted()) {
            return;
        }

        $totalSuccess = $this->aggregator->getTotalSuccess()->getValue();
        $totalFailed = $this->aggregator->getTotalFailed()->getValue();
        $totalErrors = $this->aggregator->getTotalErrors()->getValue();
        $cycleCount = $this->aggregator->getCycleCount();

        $total = $totalSuccess + $totalFailed;

        if ($total === 0) {
            return;
        }

        $this->output->line(
            sprintf(
                '  📊 Total: %d tasks (%d success, %d failed, %d errors) over %d cycles',
                $total,
                $totalSuccess,
                $totalFailed,
                $totalErrors,
                $cycleCount
            )
        );
    }

    /**
     * Displays the final detailed summary.
     */
    private function displayFinalSummary(): void
    {
        if ($this->output->isMuted()) {
            return;
        }

        $summary = $this->aggregator->getDetailedSummary();

        $this->output->line();
        $this->output->title('📊 Final Execution Summary');
        $this->output->line();

        $this->output->line('📌 Totals:');
        $this->output->line(sprintf('   ✅ Success  : %d', $summary->total->success));
        $this->output->line(sprintf('   ❌ Failed   : %d', $summary->total->failed));
        $this->output->line(sprintf('   ⚠️ Errors   : %d', $summary->total->errors));
        $this->output->line();

        $this->output->line('🔄 Unique tasks:');
        $this->output->line(sprintf('   ✅ Success  : %d', $summary->unique->success));
        $this->output->line(sprintf('   ❌ Failed   : %d', $summary->unique->failed));
        $this->output->line();

        $this->output->line('🔁 Recurring tasks:');
        $this->output->line(sprintf('   ✅ Success  : %d', $summary->recurring->success));
        $this->output->line(sprintf('   ❌ Failed   : %d', $summary->recurring->failed));
        $this->output->line();

        if ($this->output->isVerbose()) {
            $history = $this->aggregator->getCycleHistory();
            if (count($history) > 1) {
                $this->output->line('📈 Cycle history:');
                foreach ($history as $cycle => $data) {
                    $total = $data->success + $data->failed;
                    $status = $data->failed > 0 || $data->errors > 0 ? '⚠️' : '✅';
                    $this->output->line(
                        sprintf(
                            '   %s Cycle #%d: %d tasks (success: %d, failed: %d, errors: %d)',
                            $status,
                            $cycle,
                            $total,
                            $data->success,
                            $data->failed,
                            $data->errors
                        )
                    );
                }
                $this->output->line();
            }
        }
    }

    /**
     * Displays the remaining tasks count.
     */
    private function displayRemainingTasks(): void
    {
        if ($this->output->isMuted()) {
            return;
        }

        $now = new Iso8601DateTimeVO;
        $nowCarbon = $now->toCarbon();

        $uniquePending = UniqueTask::where('status', 'pending')
            ->where('scheduled_at', '<=', $nowCarbon)
            ->count();

        $recurringPlaying = RecurringTask::where('status', 'playing')->count();
        $recurringWaiting = RecurringTask::where('status', 'waiting')->count();

        $this->output->remainingTasks($uniquePending, $recurringPlaying, $recurringWaiting);
    }

    /**
     * Displays the final remaining tasks status.
     */
    private function displayFinalRemaining(): void
    {
        if ($this->output->isMuted()) {
            return;
        }

        $now = new Iso8601DateTimeVO;
        $nowCarbon = $now->toCarbon();

        $uniquePending = UniqueTask::where('status', 'pending')
            ->where('scheduled_at', '<=', $nowCarbon)
            ->count();

        $recurringPlaying = RecurringTask::where('status', 'playing')->count();
        $recurringWaiting = RecurringTask::where('status', 'waiting')->count();

        $uniqueTotal = UniqueTask::count();
        $recurringTotal = RecurringTask::count();

        $uniqueCompleted = UniqueTask::where('status', 'completed')->count();
        $recurringFinished = RecurringTask::where('status', 'finished')->count();

        $this->output->line();
        $this->output->title('📊 Final Status');
        $this->output->line();

        $this->output->line('📌 Unique tasks:');
        $this->output->line(sprintf('   Total      : %d', $uniqueTotal));
        $this->output->line(sprintf('   ✅ Completed: %d', $uniqueCompleted));
        $this->output->line(sprintf('   ⏳ Pending  : %d', $uniquePending));
        $this->output->line();

        $this->output->line('🔄 Recurring tasks:');
        $this->output->line(sprintf('   Total      : %d', $recurringTotal));
        $this->output->line(sprintf('   ✅ Finished : %d', $recurringFinished));
        $this->output->line(sprintf('   ▶️  Playing  : %d', $recurringPlaying));
        $this->output->line(sprintf('   ⏳ Waiting  : %d', $recurringWaiting));
        $this->output->line();

        $totalRemaining = $uniquePending + $recurringPlaying + $recurringWaiting;
        $this->output->line(sprintf('📦 Total remaining: %d', $totalRemaining));
        $this->output->line();

        $this->output->info('💡 Tip: Use --verbose to see detailed execution logs');
        $this->output->line();
    }

    /**
     * Gets the interval duration from arguments.
     */
    private function getInterval(): DurationVO
    {
        $interval = (float) ($this->getArgument('interval') ?? 60);

        return new DurationVO(max($interval, self::MIN_INTERVAL_SECONDS));
    }

    /**
     * Gets the total duration from arguments.
     */
    private function getDuration(): ?DurationVO
    {
        $duration = $this->getArgument('duration');

        return $duration !== null ? new DurationVO((float) $duration) : null;
    }

    /**
     * Gets the task limit from arguments.
     */
    private function getLimit(): ?LimitVO
    {
        $limit = $this->getArgument('limit');

        return $limit !== null ? new LimitVO((int) $limit) : null;
    }

    /**
     * Gets the number of parallel workers from arguments.
     */
    private function getParallelWorkers(): int
    {
        $parallel = $this->getArgument('parallel');

        return $parallel !== null ? max(1, (int) $parallel) : 1;
    }

    /**
     * Waits for the specified duration while handling signals.
     */
    private function waitWithSignals(DurationVO $waitTime): void
    {
        $seconds = $waitTime->getValue();
        $start = microtime(true);
        $elapsed = 0.0;

        while ($elapsed < $seconds) {
            if ($this->signalHandler->shouldStop()) {
                break;
            }

            $this->signalHandler->dispatch();

            $remaining = $seconds - $elapsed;
            $sleepTime = min(0.1, $remaining);

            if ($sleepTime > 0) {
                usleep((int) ($sleepTime * 1000000));
            }

            $elapsed = microtime(true) - $start;
        }
    }

    /**
     * Displays the start message with configuration details.
     */
    private function displayStartMessage(): void
    {
        if ($this->output->isMuted()) {
            return;
        }

        $this->output->line(sprintf('  Interval: %ds', (int) $this->getInterval()->getValue()));

        $duration = $this->getDuration();
        if ($duration !== null) {
            $totalCycles = $this->cycleCalculator->getTotalCycles();
            $estimatedDuration = $this->cycleCalculator->getEstimatedDuration();

            $this->output->line(sprintf(
                '  Duration: %ds (estimated: %ds, %d cycles)',
                (int) $duration->getValue(),
                (int) $estimatedDuration,
                $totalCycles
            ));
        }

        $workers = $this->getParallelWorkers();
        if ($workers > 1) {
            $this->output->line(sprintf('  Workers: %d', $workers));
        }

        $limit = $this->getLimit();
        if ($limit !== null) {
            $this->output->line(sprintf('  Limit: %d', $limit->getValue()));
        }

        $options = [];
        if ($this->isFlagActive('unique-only')) {
            $options[] = '--unique-only';
        }
        if ($this->isFlagActive('recurring-only')) {
            $options[] = '--recurring-only';
        }
        if ($this->isFlagActive('verbose')) {
            $options[] = '--verbose';
        }

        if (! empty($options)) {
            $this->output->line('  Options: '.implode(' ', $options));
        }

        $this->output->line('Press Ctrl+C to stop');
        $this->output->line();
    }
}
