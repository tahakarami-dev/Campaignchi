<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Core;

/**
 * Abstract Service Provider
 *
 * Each module extends this class to register its own
 * bindings and WordPress hooks in isolation.
 *
 * @package Msi\Campaignchi\Core
 */
abstract class ServiceProvider
{
    public function __construct(protected Container $container) {}

    /**
     * Register bindings into the container.
     * Called before boot() — no WordPress hooks here.
     */
    abstract public function register(): void;

    /**
     * Boot the provider — register hooks, load assets, etc.
     * Called after all providers are registered.
     */
    abstract public function boot(): void;
}
