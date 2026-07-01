<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple Dependency Injection Container
 *
 * Supports:
 *  - Binding closures / concrete classes
 *  - Singleton binding
 *  - Auto-resolution (make)
 *
 * @package Msi\Campaignchi\Core
 */
class Container
{
    /** @var array<string, callable> Registered bindings */
    private array $bindings = [];

    /** @var array<string, object> Singleton instances cache */
    private array $instances = [];

    // -------------------------------------------------------
    // Binding
    // -------------------------------------------------------

    /**
     * Bind an abstract to a concrete resolver.
     *
     * @param string   $abstract The key / interface name
     * @param callable $resolver Closure that returns the resolved instance
     */
    public function bind(string $abstract, callable $resolver): void
    {
        $this->bindings[$abstract] = $resolver;
    }

    /**
     * Bind as singleton — resolved once, cached forever.
     *
     * @param string   $abstract
     * @param callable $resolver
     */
    public function singleton(string $abstract, callable $resolver): void
    {
        $this->bindings[$abstract] = function () use ($abstract, $resolver) {
            if (!isset($this->instances[$abstract])) {
                $this->instances[$abstract] = $resolver($this);
            }
            return $this->instances[$abstract];
        };
    }

    /**
     * Register an already-constructed object as a singleton.
     *
     * @param string $abstract
     * @param object $instance
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    // -------------------------------------------------------
    // Resolution
    // -------------------------------------------------------

    /**
     * Resolve a binding from the container.
     *
     * @param string $abstract
     * @return mixed
     * @throws \RuntimeException If binding not found
     */
    public function make(string $abstract): mixed
    {
        // Return cached singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Resolve via registered binding
        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this);
        }

        // Auto-instantiate if class exists (no constructor args)
        if (class_exists($abstract)) {
            return new $abstract();
        }

        throw new \RuntimeException(
            sprintf('[Campaignchi] Container: Unable to resolve "%s".', $abstract)
        );
    }

    /**
     * Check if a binding exists.
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }
}
