<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\WatchRendererServiceInterface;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

class WatchRendererService implements WatchRendererServiceInterface
{
    public function __construct(
        private readonly DirectiveInteractionService $interaction,
        private readonly DurationFormatterService $formatter,
    ) {}

    public function renderStartMessage(
        ?int $duration,
        int $intervalSeconds,
        StringTypedCollection $options,
        bool $testingMode
    ): void {
        $this->interaction->newLine();
        $this->interaction->info('🚀 Starting tasks watch loop...');

        if ($testingMode) {
            $this->interaction->info('   🔬 Mode: TESTING (in-process execution)');
        }

        if ($duration !== null) {
            $durationSeconds = (int) $duration;
            $this->interaction->info(sprintf(
                '   Duration: %d seconds (%s)',
                $durationSeconds,
                $this->formatter->formatDuration($durationSeconds)
            ));
        } else {
            $this->interaction->info('   Duration: unlimited (Ctrl+C to stop)');
        }

        $this->interaction->info(sprintf(
            '   Interval: %d seconds (%s)',
            $intervalSeconds,
            $this->formatter->formatDuration($intervalSeconds)
        ));

        if ($options->isNotEmpty()) {
            $this->interaction->info('   Options: '.$options->join(' '));
        }

        $this->interaction->newLine();
        $this->interaction->separator('=', 80);
    }

    public function renderCycleStart(int $cycleNumber, Iso8601DateTimeVO $startedAt): void
    {
        $time = $startedAt->toDateTime()->format('H:i:s');
        $this->interaction->newLine();
        $this->interaction->info(sprintf('🔄 Cycle #%d (started at %s):', $cycleNumber, $time));
    }

    public function renderCycleEnd(
        CycleResultRecord $result,
        Iso8601DateTimeVO $startedAt,
        int $intervalSeconds
    ): void {
        $totalProcessed = $result->success + $result->failed;

        if ($totalProcessed === 0) {
            $this->interaction->info('  ⏳ No tasks to process');
        } else {
            $this->interaction->info(sprintf(
                '  ✅ %d tasks succeeded, ❌ %d tasks failed',
                $result->success,
                $result->failed
            ));
        }

        $elapsed = $this->formatter->calculateElapsedSeconds($startedAt);
        $this->interaction->info(sprintf('  ⏱️  Cycle duration: %.2f seconds', $elapsed));

        $remaining = max(0, $intervalSeconds - $elapsed);
        if ($remaining > 0) {
            $this->interaction->info(sprintf('  ⏳ Next cycle in %.0f seconds...', $remaining));
        }
    }

    public function renderSummary(
        int $cycleCount,
        int $totalSuccess,
        int $totalFailed,
        int $totalErrors,
        Iso8601DateTimeVO $startedAt,
        bool $testingMode,
        bool $stoppedBySignal,
        bool $durationReached,
        ?string $exception = null
    ): void {
        $this->interaction->newLine();
        $this->interaction->separator('=', 80);
        $this->interaction->info('📊 === Summary ===');

        if ($testingMode) {
            $this->interaction->info('   🔬 Mode: TESTING');
        }

        $this->interaction->info(sprintf('  Cycles executed:  %d', $cycleCount));
        $this->interaction->info(sprintf('  Total success:    %d', $totalSuccess));
        $this->interaction->info(sprintf('  Total failures:   %d', $totalFailed));
        $this->interaction->info(sprintf('  Total errors:     %d', $totalErrors));

        $totalDuration = $this->formatter->calculateElapsedSeconds($startedAt);
        $this->interaction->info(sprintf(
            '  Total duration:   %s',
            $this->formatter->formatDuration((int) $totalDuration)
        ));

        $this->interaction->newLine();

        if ($stoppedBySignal) {
            $this->interaction->info('🛑 Stopped by user signal');
        } elseif ($durationReached) {
            $this->interaction->info('⏰ Duration reached. Stopping gracefully...');
        }

        $this->interaction->separator('=', 80);
        $this->interaction->newLine();

        if ($exception) {
            $this->interaction->error($exception);
        }
    }

    public function renderInterruptSignal(string $signalName): void
    {
        $this->interaction->warn("\n⚠️  Received {$signalName}, stopping after current cycle...");
    }

    public function renderTestingModeEnabled(): void
    {
        $this->interaction->info('🧪 Testing mode enabled');
    }
}
