<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Handlers\OutputHandler;
use AndyDefer\Task\Handlers\SignalHandler;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\Services\Watchs\ParallelExecutor;
use AndyDefer\Task\Services\Watchs\ResultAggregator;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use RuntimeException;
use Throwable;

final class TasksWatchDirective extends AbstractDirective
{
    private const MIN_INTERVAL_SECONDS = 2;

    private OutputHandler $output;

    private SignalHandler $signalHandler;

    private CycleCalculator $cycleCalculator;

    private ParallelExecutor $parallelExecutor;

    private ResultAggregator $aggregator;

    public function getSignature(): string
    {
        return 'tasks:watch {interval=60} {duration=?} {limit=100} {parallel=?} {--unique-only} {--recurring-only} {--verbose} {--mute}';
    }

    public function getDescription(): string
    {
        return 'Watch and process tasks in a continuous loop with configurable interval (in seconds, min 2) and duration. Use --parallel=N for parallel execution with N workers. Use --mute to suppress all console output.';
    }

    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from(['task-watch', 'tw']);
    }

    protected function execute(): ExitCode
    {
        try {
            $this->boot();

            $this->output->info('👀 Starting task watch...');
            $this->displayStartMessage();

            $this->signalHandler->install();

            $startedAt = new Iso8601DateTimeVO;
            $hasFailures = false;
            $cycleNumber = 0;

            while ($this->cycleCalculator->shouldContinue($cycleNumber, $this->signalHandler->shouldStop())) {
                $this->signalHandler->dispatch();

                if ($this->signalHandler->shouldStop()) {
                    break;
                }

                $cycleNumber++;
                $this->aggregator->startNewCycle();

                // ✅ Enregistrer le début du cycle
                $cycleStartTime = microtime(true);

                $this->output->line();
                $this->output->line(sprintf('🔄 Cycle #%d', $cycleNumber));

                $cycleResults = $this->executeCycle();

                if (! empty($cycleResults)) {
                    $this->aggregator->addResults($cycleResults);
                }

                $this->displayCycleSummaryDetailed($cycleNumber, $cycleResults);

                // ✅ Calculer le temps d'attente réel
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

            $this->displayFinalSummary($startedAt);

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
        }
    }

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
            $console,
            $kernel,
            $this->output
        );
        $this->aggregator = new ResultAggregator;
    }

    private function executeCycle(): array
    {
        $uniqueOnly = $this->isFlagActive('unique-only');
        $recurringOnly = $this->isFlagActive('recurring-only');
        $verbose = $this->isFlagActive('verbose');
        $limit = $this->getLimit();

        return $this->parallelExecutor->execute(
            uniqueOnly: $uniqueOnly,
            recurringOnly: $recurringOnly,
            limit: $limit,
            verbose: $verbose,
            muted: $this->output->isMuted()
        );
    }

    private function displayCycleSummaryDetailed(int $cycleNumber, array $results): void
    {
        if ($this->output->isMuted()) {
            return;
        }

        $totalSuccess = 0;
        $totalFailed = 0;
        $totalErrors = 0;
        $uniqueSuccess = 0;
        $uniqueFailed = 0;
        $recurringSuccess = 0;
        $recurringFailed = 0;

        foreach ($results as $result) {
            if ($result instanceof TaskExecutionResultRecord) {
                $success = $result->success->getValue();
                $failed = $result->failed->getValue();
                $errors = $result->errors->count();

                $totalSuccess += $success;
                $totalFailed += $failed;
                $totalErrors += $errors;

                if ($result->type->value === 'unique') {
                    $uniqueSuccess += $success;
                    $uniqueFailed += $failed;
                } elseif ($result->type->value === 'recurring') {
                    $recurringSuccess += $success;
                    $recurringFailed += $failed;
                }
            }
        }

        $this->output->cycleSummaryDetailed(
            $cycleNumber,
            $totalSuccess,
            $totalFailed,
            $totalErrors,
            $uniqueSuccess,
            $uniqueFailed,
            $recurringSuccess,
            $recurringFailed
        );
    }

    private function getInterval(): DurationVO
    {
        $interval = (float) ($this->getArgument('interval') ?? 60);

        return new DurationVO(max($interval, self::MIN_INTERVAL_SECONDS));
    }

    private function getDuration(): ?DurationVO
    {
        $duration = $this->getArgument('duration');

        return $duration !== null ? new DurationVO((float) $duration) : null;
    }

    private function getLimit(): ?LimitVO
    {
        $limit = $this->getArgument('limit');

        return $limit !== null ? new LimitVO((int) $limit) : null;
    }

    private function getParallelWorkers(): int
    {
        $parallel = $this->getArgument('parallel');

        return $parallel !== null ? max(1, (int) $parallel) : 1;
    }

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

    private function displayFinalSummary(Iso8601DateTimeVO $startedAt): void
    {
        if ($this->output->isMuted()) {
            return;
        }

        $elapsed = $startedAt->elapsed();
        $duration = $this->getDuration();

        $this->output->finalSummary(
            totalCycles: $this->aggregator->getCycleCount(),
            totalSuccess: $this->aggregator->getTotalSuccess()->getValue(),
            totalFailed: $this->aggregator->getTotalFailed()->getValue(),
            totalErrors: $this->aggregator->getTotalErrors()->getValue(),
            uniqueSuccess: $this->aggregator->getUniqueSuccess()->getValue(),
            uniqueFailed: $this->aggregator->getUniqueFailed()->getValue(),
            recurringSuccess: $this->aggregator->getRecurringSuccess()->getValue(),
            recurringFailed: $this->aggregator->getRecurringFailed()->getValue(),
            elapsedSeconds: $elapsed->getValue(),
            plannedDuration: $duration !== null ? (int) $duration->getValue() : null,
            stoppedBySignal: $this->signalHandler->shouldStop(),
            workers: $this->getParallelWorkers()
        );
    }
}
