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
use AndyDefer\Task\Helpers\JsonlResultHelper;
use AndyDefer\Task\Helpers\SessionHelper;
use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\Services\Watchs\ParallelExecutor;
use AndyDefer\Task\Services\Watchs\ResultAggregator;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use Illuminate\Support\Facades\DB;
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
        return 'tasks:watch 
                    {interval=60}#"Interval between cycles in seconds (minimum 2s)" 
                    {duration=?}#"Total execution duration in seconds (unlimited if omitted)" 
                    {limit=100}#"Maximum tasks to process per cycle" 
                    {parallel=?}#"Number of parallel workers (1 by default)" 
                    {--unique-only}#"Process only unique tasks" 
                    {--recurring-only}#"Process only recurring tasks" 
                    {--verbose}#"Show detailed execution logs" 
                    {--mute}#"Suppress all console output"';
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
        $sessionId = SessionHelper::generate();
        JsonlResultHelper::init($sessionId);

        try {
            $this->boot();

            $this->output->info('👀 Starting task watch...');
            $this->output->debug("Session ID: {$sessionId}");
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

                $cycleStartTime = microtime(true);

                $this->output->line();
                $this->output->line(sprintf('🔄 Cycle #%d', $cycleNumber));

                $cycleResults = $this->executeCycle();

                if (! empty($cycleResults)) {
                    $this->aggregator->addResults($cycleResults);
                }

                // ✅ Afficher ce qui RESTE à faire
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

            // ✅ Afficher le résumé final : ce qui RESTE
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

    /**
     * Affiche les tâches restantes à exécuter.
     */
    private function displayRemainingTasks(): void
    {
        if ($this->output->isMuted()) {
            return;
        }

        $now = new Iso8601DateTimeVO;

        // ✅ Compter les tâches uniques PENDING prêtes
        $uniquePending = DB::table('unique_tasks')
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', $now->forDatabase())
            ->count();

        // ✅ Compter les tâches récurrentes PLAYING
        $recurringPlaying = DB::table('recurring_tasks')
            ->where('status', 'playing')
            ->count();

        // ✅ Compter les tâches récurrentes WAITING
        $recurringWaiting = DB::table('recurring_tasks')
            ->where('status', 'waiting')
            ->count();

        $this->output->remainingTasks($uniquePending, $recurringPlaying, $recurringWaiting);
    }

    /**
     * Affiche le résumé final : ce qui RESTE.
     */
    private function displayFinalRemaining(): void
    {
        if ($this->output->isMuted()) {
            return;
        }

        $now = new Iso8601DateTimeVO;

        // ✅ Compter les tâches restantes
        $uniquePending = DB::table('unique_tasks')
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', $now->forDatabase())
            ->count();

        $recurringPlaying = DB::table('recurring_tasks')
            ->where('status', 'playing')
            ->count();

        $recurringWaiting = DB::table('recurring_tasks')
            ->where('status', 'waiting')
            ->count();

        $uniqueTotal = DB::table('unique_tasks')->count();
        $recurringTotal = DB::table('recurring_tasks')->count();

        $uniqueCompleted = DB::table('unique_tasks')
            ->where('status', 'completed')
            ->count();

        $recurringFinished = DB::table('recurring_tasks')
            ->where('status', 'finished')
            ->count();

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
}
