<?php

namespace AndyDefer\Task\Bootstrap;

use AndyDefer\Directive\Container\LaravelContainerAdapter;
use AndyDefer\Directive\DirectiveKernel;

final readonly class TaskDirectiveKernelFactory
{
    public static function create(): DirectiveKernel
    {
        $container = new LaravelContainerAdapter(ApplicationFactory::create());

        return DirectiveKernel::init($container);
    }
}
