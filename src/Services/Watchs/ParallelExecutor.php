<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\Task\Handlers\OutputHandler;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\ValueObjects\LimitVO;
use Illuminate\Support\Facades\DB;
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
                // Processus enfant
                socket_close($pipe[0]);

                try {
                    // 🔥 SOLUTION CLÉ : Réinitialiser complètement la connexion MySQL
                    // pour éviter les conflits entre processus
                    $this->resetDatabaseConnection();

                    $result = $this->runWorker($i, $uniqueOnly, $recurringOnly, $limit, $verbose, $muted);

                    $data = $result !== null ? serialize($result) : 'null';
                    socket_write($pipe[1], $data, strlen($data));
                    socket_close($pipe[1]);

                    exit(0);
                } catch (Throwable $e) {
                    // Gérer l'erreur et la transmettre au parent
                    $errorMessage = 'error:'.$e->getMessage();
                    socket_write($pipe[1], $errorMessage, strlen($errorMessage));
                    socket_close($pipe[1]);
                    exit(1);
                }
            } else {
                // Processus parent
                socket_close($pipe[1]);
                $pids[$pid] = $pipe[0];
            }
        }

        // Attendre tous les workers et collecter les résultats
        foreach ($pids as $pid => $pipe) {
            $status = null;
            pcntl_waitpid($pid, $status, 0);

            // Vérifier si le processus enfant s'est terminé correctement
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

            // Lire les données du pipe
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
                // Réinitialiser la connexion avant chaque worker séquentiel
                $this->resetDatabaseConnection();

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

        // Toujours activer --mute pour éviter les conflits d'output
        $argv[] = '--mute';

        $this->kernel->getContext()->put('worker_id', $workerId);

        try {
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
        } catch (Throwable $e) {
            $this->output->error("❌ Worker {$workerId} threw exception: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Réinitialise complètement la connexion à la base de données
     * pour éviter les conflits entre processus.
     */
    private function resetDatabaseConnection(): void
    {
        try {
            // 1. Purger toutes les connexions existantes
            DB::purge();

            // 2. Reconnecter avec une nouvelle connexion
            DB::reconnect();

            // 3. Vérifier que la connexion fonctionne
            DB::connection()->getPdo();

            $this->output->debug('✅ Database connection reset successfully');
        } catch (Throwable $e) {
            $this->output->error('❌ Failed to reset database connection: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Méthode utilitaire pour exécuter un worker avec une gestion d'erreur améliorée
     */
    private function executeWorkerWithRetry(
        int $workerId,
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $muted = false,
        int $maxRetries = 3
    ): ?TaskExecutionResultRecord {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $attempt++;
                $this->output->debug("🔧 Worker {$workerId} attempt {$attempt}/{$maxRetries}");

                // Réinitialiser la connexion à chaque tentative
                $this->resetDatabaseConnection();

                return $this->runWorker($workerId, $uniqueOnly, $recurringOnly, $limit, $verbose, $muted);
            } catch (Throwable $e) {
                $this->output->error("❌ Worker {$workerId} attempt {$attempt} failed: ".$e->getMessage());

                if ($attempt >= $maxRetries) {
                    $this->output->error("❌ Worker {$workerId} failed after {$maxRetries} attempts");
                    throw $e;
                }

                // Attendre un peu avant de réessayer
                usleep(500000); // 0.5 seconde
            }
        }

        return null;
    }
}
