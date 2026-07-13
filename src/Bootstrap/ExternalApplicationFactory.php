<?php

declare(strict_types=1);

namespace AndyDefer\Task\Bootstrap;

use AndyDefer\Directive\Exceptions\BootstrapException;
use AndyDefer\Directive\Helpers\Paths;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;

/**
 * Factory for bootstrapping the Laravel application.
 *
 * This class handles environment loading, autoloader registration, application
 * creation, service provider registration, and application bootstrapping.
 */
final readonly class ExternalApplicationFactory
{
    /**
     * The package name used in compiled providers storage.
     */
    private const PACKAGE_KEY = 'andydefer/laravel-directive';

    /**
     * Creates a fully bootstrapped application instance.
     *
     * @return Application The bootstrapped Laravel application
     *
     * @throws BootstrapException If bootstrapping fails
     */
    public static function create(): Application
    {
        self::loadEnvironment();
        self::loadAutoloader();

        $app = self::createApplication();
        self::registerProviders($app);
        self::bootApplication($app);

        return $app;
    }

    /**
     * Loads environment variables from the .env file.
     */
    private static function loadEnvironment(): void
    {
        if (! Paths::hasEnvFile() || ! function_exists('putenv')) {
            return;
        }

        $lines = file(Paths::envFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '#') || ! str_contains($line, '=')) {
                continue;
            }

            putenv($line);
        }
    }

    /**
     * Loads the Composer autoloader and registers package autoloading.
     *
     * @throws BootstrapException If the autoloader is not found
     */
    private static function loadAutoloader(): void
    {
        if (! Paths::hasProjectAutoload()) {
            throw new BootstrapException(
                'Autoloader not found at ['.Paths::projectAutoload()."]. Run 'composer install' first."
            );
        }

        require_once Paths::projectAutoload();

        if (Paths::hasPackageAutoload() && Paths::packageAutoload() !== Paths::projectAutoload()) {
            require_once Paths::packageAutoload();
        }
    }

    /**
     * Creates and returns the Laravel application instance.
     *
     * @return Application The bootstrapped application
     *
     * @throws BootstrapException If the bootstrap file is missing or invalid
     */
    private static function createApplication(): Application
    {
        if (! Paths::hasLaravelBootstrap()) {
            throw new BootstrapException(
                'Laravel bootstrap file not found at ['.Paths::laravelBootstrap().'].'
            );
        }

        $app = require Paths::laravelBootstrap();

        if (! $app instanceof Application) {
            throw new BootstrapException(
                'Bootstrap file must return an instance of '.Application::class
            );
        }

        return $app;
    }

    /**
     * Registers all service providers from storage and configuration.
     *
     * @param  Application  $app  The application instance
     */
    private static function registerProviders(Application $app): void
    {
        $providers = array_merge(
            self::resolveProvidersFromStorage(),
            self::resolveProvidersFromConfig()
        );

        $validProviders = self::filterValidProviders($providers);

        foreach ($validProviders as $provider) {
            $app->register($provider);
        }
    }

    /**
     * Filters and validates provider class names.
     *
     * @param  array<string|class-string>  $providers  The provider class names to validate
     * @return list<class-string> The validated provider class names
     */
    private static function filterValidProviders(array $providers): array
    {
        return array_values(
            array_filter($providers, fn ($provider): bool => is_string($provider) && class_exists($provider))
        );
    }

    /**
     * Resolves service providers from the compiled providers storage.
     *
     * @return list<class-string> The resolved provider class names
     */
    private static function resolveProvidersFromStorage(): array
    {
        if (! Paths::hasCompiledProviders()) {
            return [];
        }

        /** @var array<string, mixed> $providersData */
        $providersData = require Paths::compiledProviders();

        $providers = $providersData['providers'] ?? [];

        if (isset($providersData[self::PACKAGE_KEY]['providers']) && is_array($providersData[self::PACKAGE_KEY]['providers'])) {
            $providers = array_merge($providers, $providersData[self::PACKAGE_KEY]['providers']);
        }

        return array_values(array_filter($providers, 'is_string'));
    }

    /**
     * Resolves service providers from the application configuration.
     *
     * @return list<class-string> The resolved provider class names
     */
    private static function resolveProvidersFromConfig(): array
    {
        if (! Paths::hasAppConfig()) {
            return [];
        }

        /** @var array<string, mixed> $config */
        $config = require Paths::appConfig();

        $providers = $config['providers'] ?? [];

        return is_array($providers) ? array_values(array_filter($providers, 'is_string')) : [];
    }

    /**
     * Boots the Laravel application.
     *
     * @param  Application  $app  The application to boot
     */
    private static function bootApplication(Application $app): void
    {
        $app->make(Kernel::class)->bootstrap();
    }
}
