<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Frontend;

use Msi\Campaignchi\Core\ServiceProvider;
use Msi\Campaignchi\Core\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend Service Provider
 *
 * Handles public-facing output:
 *  - Shortcodes
 *  - Frontend asset loading
 *  - Renderer registration
 *
 * @package Msi\Campaignchi\Frontend
 */
class FrontendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Future: bind Renderer, ShortcodeRegistry, etc.
    }

    public function boot(): void
    {
        // Only load frontend assets on non-admin pages
        if (is_admin()) {
            return;
        }

        Hooks::action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Enqueue frontend assets (countdown timer, slider, etc.)
     */
    public function enqueueAssets(): void
    {
        wp_enqueue_style(
            'cmc-frontend',
            CMC_ASSETS_URL . 'css/frontend.css',
            [],
            CMC_VERSION
        );
    }
}
