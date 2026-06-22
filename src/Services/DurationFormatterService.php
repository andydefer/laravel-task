<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

/**
 * Service utilitaire pour le formatage des durées.
 * Utilisé par WatchRendererService et WatchService.
 */
final class DurationFormatterService
{
    public function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }

        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }

        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs}s";
        }

        return implode(' ', $parts);
    }

    public function calculateElapsedSeconds(?Iso8601DateTimeVO $start): float
    {
        if ($start === null) {
            return 0;
        }

        $end = new Iso8601DateTimeVO;
        $startDateTime = $start->toDateTime();
        $endDateTime = $end->toDateTime();

        $startFloat = (float) $startDateTime->format('U.u');
        $endFloat = (float) $endDateTime->format('U.u');

        return round($endFloat - $startFloat, 2);
    }
}
