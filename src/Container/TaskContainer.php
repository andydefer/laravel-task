<?php

declare(strict_types=1);

namespace AndyDefer\Task\Container;

use AndyDefer\Directive\Container\Container;
use Illuminate\Foundation\Application;

/**
 * Complete Task container with all services pre-registered.
 *
 * Use this for standalone applications or when you want a
 * ready-to-use container for Laravel Task without Laravel.
 */
class TaskContainer extends Container
{
    protected function __construct(
        private readonly Application $app,
        string $basePath = __DIR__
    ) {
        parent::__construct($basePath);
    }

    /**
     * Create a new instance of the class
     */
    public static function create(Application $app, string $basePath = __DIR__): static
    {
        return new static($app, $basePath);
    }

    /**
     * {@inheritdoc}
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        return $this->app->make($abstract, $parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $provider): void
    {
        $this->app->register($provider);
    }

    /**
     * {@inheritdoc}
     */
    public function basePath(): string
    {
        return $this->app->basePath();
    }

    /**
     * {@inheritdoc}
     */
    public function version(): ?string
    {
        return $this->app->version();
    }
}
