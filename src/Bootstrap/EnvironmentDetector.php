<?php

declare(strict_types=1);

namespace AndyDefer\Task\Bootstrap;

use AndyDefer\Directive\Enums\ApplicationType;
use AndyDefer\Directive\Helpers\Paths;

final class EnvironmentDetector
{
    /**
     * Detect if the current execution is inside a package/library.
     */
    public static function isPackage(): bool
    {
        // 1. Vérifier la présence du dossier vendor à la racine
        $vendorPath = Paths::projectRoot().'/vendor';
        if (! is_dir($vendorPath)) {
            return false;
        }

        // 2. Vérifier si le fichier composer.json contient "type": "library" ou "project"
        $composerPath = Paths::projectRoot().'/composer.json';
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            $type = $composer['type'] ?? null;

            // ✅ Si c'est une library ou un package
            if ($type === 'library' || $type === 'package') {
                return true;
            }

            // ✅ Si le nom correspond à un package (contient /)
            if (isset($composer['name']) && str_contains($composer['name'], '/')) {
                return true;
            }
        }

        // 3. Vérifier la présence d'un fichier de configuration Laravel
        if (file_exists(Paths::projectRoot().'/config/app.php')) {
            return false; // C'est une application Laravel complète
        }

        // 4. Vérifier si on est dans le dossier vendor
        if (str_contains(__DIR__, '/vendor/')) {
            return true;
        }

        return false;
    }

    /**
     * Detect if the current execution is inside a Laravel web application.
     */
    public static function isWebApplication(): bool
    {
        // 1. Présence des fichiers d'application Laravel
        $hasConfig = file_exists(Paths::projectRoot().'/config/app.php');
        $hasBootstrap = file_exists(Paths::projectRoot().'/bootstrap/app.php');
        $hasPublic = is_dir(Paths::projectRoot().'/public');

        if ($hasConfig && $hasBootstrap && $hasPublic) {
            return true;
        }

        // 2. Vérifier le composer.json
        $composerPath = Paths::projectRoot().'/composer.json';
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            $type = $composer['type'] ?? null;

            // ✅ Type "project" = application
            if ($type === 'project') {
                return true;
            }

            // ✅ Présence des dépendances Laravel
            $requires = array_merge(
                $composer['require'] ?? [],
                $composer['require-dev'] ?? []
            );

            if (isset($requires['laravel/framework'])) {
                return true;
            }
        }

        // 3. Présence du fichier .env
        if (file_exists(Paths::projectRoot().'/.env')) {
            return true;
        }

        return false;
    }

    /**
     * Detect if the current execution is inside a package/library.
     * Alias for isPackage().
     */
    public static function isLibrary(): bool
    {
        return self::isPackage();
    }

    /**
     * Get the application type as a string.
     */
    public static function getApplicationType(): string
    {
        if (self::isWebApplication()) {
            return 'web_application';
        }

        if (self::isPackage()) {
            return 'package';
        }

        return 'unknown';
    }

    /**
     * Get the application type as an enum.
     */
    public static function getApplicationTypeEnum(): ApplicationType
    {
        if (self::isWebApplication()) {
            return ApplicationType::WEB_APPLICATION;
        }

        if (self::isPackage()) {
            return ApplicationType::PACKAGE;
        }

        return ApplicationType::UNKNOWN;
    }

    /**
     * Check if we are in a test environment.
     */
    public static function isTestEnvironment(): bool
    {
        return defined('PHPUNIT_COMPOSER_INSTALL')
            || getenv('PHPUNIT_RUNNING') === 'true'
            || getenv('APP_ENV') === 'testing';
    }

    /**
     * Check if we are in a development environment.
     */
    public static function isDevelopmentEnvironment(): bool
    {
        return getenv('APP_ENV') === 'local'
            || getenv('APP_ENV') === 'development'
            || getenv('APP_DEBUG') === 'true';
    }
}
