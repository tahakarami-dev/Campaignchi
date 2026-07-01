<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hooks Helper
 *
 * Thin wrapper around WordPress add_action / add_filter
 * to keep hook registration readable and centralized.
 *
 * @package Msi\Campaignchi\Core
 */
class Hooks
{
    /**
     * Register a WordPress action hook.
     *
     * @param string   $hook     WordPress action name
     * @param callable $callback Handler
     * @param int      $priority WordPress priority (default 10)
     * @param int      $args     Number of accepted arguments
     */
    public static function action(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $args = 1
    ): void {
        add_action($hook, $callback, $priority, $args);
    }

    /**
     * Register a WordPress filter hook.
     *
     * @param string   $hook
     * @param callable $callback
     * @param int      $priority
     * @param int      $args
     */
    public static function filter(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $args = 1
    ): void {
        add_filter($hook, $callback, $priority, $args);
    }

    /**
     * Remove a previously registered action.
     *
     * @param string   $hook
     * @param callable $callback
     * @param int      $priority
     */
    public static function removeAction(
        string $hook,
        callable $callback,
        int $priority = 10
    ): void {
        remove_action($hook, $callback, $priority);
    }
}
