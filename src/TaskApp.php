<?php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\Bootstrap\ApplicationBuilder;
use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\Directive\DirectiveServiceProvider;
use AndyDefer\Directive\Enums\ApplicationType;
use AndyDefer\LaravelJsonl\LaravelJsonlServiceProvider;
use AndyDefer\Logger\LoggerServiceProvider;
use AndyDefer\Task\Contracts\ApplicationInterface;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Throwable;

class TaskApp implements ApplicationInterface
{
    protected DirectiveKernel $kernel;

    protected Console $console;

    protected bool $verbose = false;

    protected function __construct(string $basePath)
    {
        $databaseFile = $basePath.'/database/database.sqlite';

        $app = ApplicationBuilder::init(ApplicationType::INTERNAL)
            ->withProviders([
                LaravelJsonlServiceProvider::class,
                DirectiveServiceProvider::class,
                LoggerServiceProvider::class,
                TaskServiceProvider::class,
            ])
            // ✅ Utiliser la nouvelle méthode withSqlite()
            ->withSqlite($databaseFile, foreignKeyConstraints: true)
            ->withConfig([
                'logger' => [
                    'base_path' => $basePath.'/storage/logs/task',
                    'buffer_size' => 100,
                ],
                'task' => [
                    'default_interval' => 60,
                    'default_limit' => 50,
                ],
            ])
            ->build();

        // ✅ Définir le Facade root
        Facade::setFacadeApplication($app);

        // ✅ Exécuter les migrations
        $this->runMigrations();

        $this->kernel = $app->make(DirectiveKernel::class);
        $this->console = $app->make(Console::class);
    }

    /**
     * Create a new TaskApp instance.
     *
     * @param  string  $basePath  The project root path
     */
    public static function create(string $basePath): static
    {
        return new static($basePath);
    }

    /**
     * Run the task application with command-line arguments.
     *
     * @param  array<int, string>  $argv  The command-line arguments
     * @return int The exit code
     */
    public function run(array $argv): int
    {
        try {
            $this->addDefaultSources();

            if ($this->verbose) {
                $this->kernel->verbose(true);
            }

            $exitCode = $this->kernel->run($argv);

            return $exitCode->value;
        } catch (Throwable $e) {
            $this->console->error('Fatal Error: '.$e->getMessage());
            $this->console->line($e->getTraceAsString());

            return 255;
        }
    }

    /**
     * Run a directive by its signature.
     *
     * @param  string  $query  The signature (e.g., "tasks:process --verbose")
     * @return int The exit code
     */
    public function runSignature(string $query): int
    {
        try {
            $this->addDefaultSources();

            if ($this->verbose) {
                $this->kernel->verbose(true);
            }

            $exitCode = $this->kernel->runSignature($query);

            return $exitCode->value;
        } catch (Throwable $e) {
            $this->console->error('Fatal Error: '.$e->getMessage());
            $this->console->line($e->getTraceAsString());

            return 255;
        }
    }

    /**
     * Run a directive by its FQCN.
     *
     * @param  string  $fqcn  The fully qualified class name
     * @param  array<int, string>  $argv  The arguments
     * @return int The exit code
     */
    public function runDirective(string $fqcn, array $argv = []): int
    {
        try {
            $this->addDefaultSources();

            if ($this->verbose) {
                $this->kernel->verbose(true);
            }

            $exitCode = $this->kernel->runDirective($fqcn, $argv);

            return $exitCode->value;
        } catch (Throwable $e) {
            $this->console->error('Fatal Error: '.$e->getMessage());
            $this->console->line($e->getTraceAsString());

            return 255;
        }
    }

    /**
     * Enable or disable verbose mode.
     *
     * @param  bool  $enabled  Whether verbose mode is enabled
     */
    public function verbose(bool $enabled = true): static
    {
        $this->verbose = $enabled;

        if ($enabled) {
            $this->kernel->verbose(true);
        } else {
            $this->kernel->verbose(false);
        }

        return $this;
    }

    /**
     * Check if verbose mode is enabled.
     */
    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    /**
     * Get the underlying kernel instance.
     */
    public function getKernel(): DirectiveKernel
    {
        return $this->kernel;
    }

    /**
     * Get the underlying application instance.
     */
    public function getApplication(): Application
    {
        return $this->kernel->getApplication();
    }

    /**
     * Run migrations for the task package.
     */
    private function runMigrations(): void
    {
        try {
            // ✅ Vérifier si les migrations doivent être exécutées
            $migrationsPath = dirname(__DIR__).'/database/migrations';

            if (! is_dir($migrationsPath)) {
                return;
            }

            // ✅ Exécuter les migrations
            Artisan::call('migrate', ['--force' => true]);

        } catch (Throwable $e) {
            // Ignorer l'erreur de migration si elle se produit
            // Les migrations seront exécutées manuellement par l'utilisateur
        }
    }

    /**
     * Add default directive sources.
     */
    private function addDefaultSources(): void
    {
        $this->kernel->addSources([
            getcwd().'/src/Directives',
            getcwd().'/tests/Fixtures/Directives',
        ]);
    }
}
