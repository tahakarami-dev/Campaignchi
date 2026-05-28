<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Core;

/**
 * Assets — Admin Asset Manager
 *
 * Handles conditional loading of CSS and JS files.
 * Only loads on CMC admin pages — never on front-end or other WP pages.
 *
 * @package Msi\Campaignchi\Core
 */
final class Assets
{
    /**
     * The WP admin menu slug used to identify our pages.
     */
    private const MENU_SLUG = 'campaignchi';

    /**
     * Register the assets hook.
     */
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Enqueue CSS and JS — only on CMC admin pages.
     *
     * @param string $hook  Current WP admin page hook suffix.
     */
    public function enqueue(string $hook): void
    {
        if (!$this->isCmcPage($hook)) {
            return;
        }

        $this->enqueueStyles();
        $this->enqueueScripts();
    }

    /**
     * Enqueue stylesheets in correct dependency order.
     */
    private function enqueueStyles(): void
    {
        // Vazirmatn Persian font from Google Fonts
        wp_enqueue_style(
            'cmc-font-vazirmatn',
            'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap',
            [],
            null
        );

        // Tabler icons
        wp_enqueue_style(
            'cmc-icons',
            'https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css',
            [],
            CMC_VERSION
        );

        // Base: design tokens, reset, layout, sidebar, topbar
        wp_enqueue_style(
            'cmc-dashboard',
            CMC_ASSETS_URL . 'css/dashboard.css',
            ['cmc-font-vazirmatn', 'cmc-icons'],
            CMC_VERSION
        );

        // Components: cards, buttons, badges, forms, tables...
        wp_enqueue_style(
            'cmc-dashboard-components',
            CMC_ASSETS_URL . 'css/dashboard-components.css',
            ['cmc-dashboard'],
            CMC_VERSION
        );
    }

    /**
     * Enqueue JavaScript files.
     */
    private function enqueueScripts(): void
    {
        wp_enqueue_script(
            'cmc-app',
            CMC_ASSETS_URL . 'js/app.js',
            [],
            CMC_VERSION,
            true // Load in footer
        );

        // Pass PHP data to JS
        wp_localize_script('cmc-app', 'CMC_DATA', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('cmc_nonce'),
            'version'  => CMC_VERSION,
        ]);
    }

    /**
     * Check if the current page is a CMC admin page.
     *
     * @param string $hook  WP admin page hook.
     */
    private function isCmcPage(string $hook): bool
    {
        // Matches: toplevel_page_campaignchi, campaignchi_page_*
        return str_contains($hook, self::MENU_SLUG);
    }
}
