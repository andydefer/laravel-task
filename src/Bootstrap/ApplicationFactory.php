<?php

namespace AndyDefer\Task\Bootstrap;

use Illuminate\Foundation\Application;

final readonly class ApplicationFactory
{
    public static function create(): Application
    {
        // ✅ Détection automatique
        if (EnvironmentDetector::isPackage()) {
            return InternalApplicationFactory::create();
        }

        return ExternalApplicationFactory::create();
    }
}
