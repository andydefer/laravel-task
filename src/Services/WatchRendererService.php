<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\WatchRendererServiceInterface;
use AndyDefer\Task\Enums\SignalName;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\StyledTextVO;

final class WatchRendererService implements WatchRendererServiceInterface
{
    public function __construct(
        private readonly DirectiveInteractionService $interaction,
    ) {}

    public function renderStartMessage(
        ?DurationVO $duration,
        DurationVO $intervalSeconds,
        StringTypedCollection $options,
        bool $testingMode
    ): void {
        $text = StyledTextVO::empty()
            ->newLine()
            ->append('🚀 Starting tasks watch loop...')
            ->newLine();

        if ($testingMode) {
            $text = $text->append('   🔬 Mode: TESTING (in-process execution)')
                ->newLine();
        }

        if ($duration !== null) {
            $text = $text->append(sprintf(
                '   Duration: %s (%s)',
                (string) $duration->seconds,
                $duration->format()
            ))->newLine();
        } else {
            $text = $text->append('   Duration: unlimited (Ctrl+C to stop)')
                ->newLine();
        }

        $text = $text->append(sprintf(
            '   Interval: %s (%s)',
            (string) $intervalSeconds->seconds,
            $intervalSeconds->format()
        ))->newLine();

        if ($options->isNotEmpty()) {
            $text = $text->append('   Options: ')->append($options->join(' '))
                ->newLine();
        }

        $text = $text->newLine()
            ->append(str_repeat('=', 80))
            ->newLine()
            ->newLine();

        $this->interaction->line($text->value);
    }

    public function renderCycleStart(CounterVO $cycleNumber, Iso8601DateTimeVO $startedAt): void
    {
        $time = $startedAt->format('H:i:s');

        $text = StyledTextVO::empty()
            ->append(sprintf('🔄 Cycle #%d (started at %s):', $cycleNumber->getValue(), $time));

        $this->interaction->line($text->value);
    }

    public function renderCycleEnd(
        CycleResultRecord $result,
        Iso8601DateTimeVO $startedAt,
        DurationVO $intervalSeconds
    ): void {
        $totalProcessed = $result->success->getValue() + $result->failed->getValue();

        $text = StyledTextVO::empty();

        if ($totalProcessed === 0) {
            $text = $text->append('  ⏳ No tasks to process');
        } else {
            $text = $text->append(sprintf(
                '  ✅ %d tasks succeeded, ❌ %d tasks failed',
                $result->success->getValue(),
                $result->failed->getValue()
            ));
        }

        $elapsed = $startedAt->elapsed();
        $text = $text->newLine()
            ->append(sprintf('  ⏱️  Cycle duration: %.2f seconds', $elapsed->seconds))
            ->newLine();

        $remaining = $intervalSeconds->seconds - $elapsed->seconds;
        if ($remaining > 0) {
            $text = $text->append(sprintf('  ⏳ Next cycle in %.0f seconds...', $remaining))
                ->newLine();
        }

        $text = $text->newLine();

        $this->interaction->line($text->value);
    }

    public function renderSummary(
        CounterVO $cycleCount,
        CounterVO $totalSuccess,
        CounterVO $totalFailed,
        CounterVO $totalErrors,
        Iso8601DateTimeVO $startedAt,
        bool $testingMode,
        bool $stoppedBySignal,
        bool $durationReached,
        ?DescriptionVO $exception = null
    ): void {
        $text = StyledTextVO::empty()
            ->newLine()
            ->append(str_repeat('=', 80))
            ->newLine()
            ->append('📊 === Summary ===')
            ->newLine();

        if ($testingMode) {
            $text = $text->append('   🔬 Mode: TESTING')
                ->newLine();
        }

        $totalDuration = $startedAt->elapsed();

        $text = $text->append(sprintf('  Cycles executed:  %d', $cycleCount->getValue()))
            ->newLine()
            ->append(sprintf('  Total success:    %d', $totalSuccess->getValue()))
            ->newLine()
            ->append(sprintf('  Total failures:   %d', $totalFailed->getValue()))
            ->newLine()
            ->append(sprintf('  Total errors:     %d', $totalErrors->getValue()))
            ->newLine()
            ->append(sprintf('  Total duration:   %s', $totalDuration->format()))
            ->newLine()
            ->newLine();

        if ($stoppedBySignal) {
            $text = $text->append('🛑 Stopped by user signal')
                ->newLine();
        } elseif ($durationReached) {
            $text = $text->append('⏰ Duration reached. Stopping gracefully...')
                ->newLine();
        }

        $text = $text->append(str_repeat('=', 80))
            ->newLine()
            ->newLine();

        if ($exception !== null) {
            $text = $text->red()
                ->append('Error: ')
                ->append($exception->getValue())
                ->reset()
                ->newLine();
        }

        $this->interaction->line($text->value);
    }

    public function renderInterruptSignal(SignalName $signalName): void
    {
        $text = StyledTextVO::empty()
            ->append("\n⚠️  Received ")
            ->append($signalName->getLabel())
            ->append(', stopping after current cycle...');

        $this->interaction->warn($text->value);
    }

    public function renderTestingModeEnabled(): void
    {
        $text = StyledTextVO::empty()
            ->append('🧪 Testing mode enabled');

        $this->interaction->info($text->value);
    }
}
