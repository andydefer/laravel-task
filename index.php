<?php

/**
 * Get the number of available CPU cores.
 */
function getCpuCores(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows
        $cores = (int) shell_exec('echo %NUMBER_OF_PROCESSORS%');

        return $cores > 0 ? $cores : 1;
    }

    // Linux / macOS
    $cores = (int) shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null');

    return $cores > 0 ? $cores : 1;
}

$cpuCores = getCpuCores();
echo "CPU cores: {$cpuCores}";
