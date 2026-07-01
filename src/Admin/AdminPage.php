<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AdminPage — WordPress Menu + Full-Screen Panel Renderer
 *
 * Strategy for "no WP chrome":
 *   WordPress renders its admin frame (header, sidebar, footer).
 *   We intercept the page load early, output our full custom HTML,
 *   and call exit — WordPress never renders its own UI.
 *
 * The menu item in wp-admin sidebar still appears normally.
 * When the user clicks it → our custom full-screen SaaS panel renders.
 */
class AdminPage
{
    /** WordPress menu slug */
    private const MENU_SLUG = 'campaignchi';

    /** Capability required to access the panel */
    private const CAPABILITY = 'manage_woocommerce';

    // ------------------------------------------------------------------
    // Register WP Admin Menu
    // ------------------------------------------------------------------

    public function registerMenu(): void
    {
        add_menu_page(
            __('کمپین‌چی', 'campaignchi'),       // Page title
            __('کمپین‌چی', 'campaignchi'),       // Menu label
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPanel'],               // Callback
            $this->getMenuIcon(),                 // SVG icon
            56                                    // Position (after WooCommerce)
        );

        // Sub-menu pages (same slug = same page, handled by JS router)
        $subPages = [
            'dashboard'  => __('داشبورد', 'campaignchi'),
            'campaigns'  => __('کمپین‌ها', 'campaignchi'),
            'analytics'  => __('گزارش‌ها', 'campaignchi'),
            'settings'   => __('تنظیمات', 'campaignchi'),
        ];

        foreach ($subPages as $slug => $label) {
            add_submenu_page(
                self::MENU_SLUG,
                $label,
                $label,
                self::CAPABILITY,
                self::MENU_SLUG . '#' . $slug,
                '__return_false'   // JS router handles routing — no PHP callback needed
            );
        }
    }

    // ------------------------------------------------------------------
    // Full-Screen Panel Renderer
    //
    // This is the core technique:
    //   1. Output our full custom HTML page
    //   2. die() — WordPress never renders its chrome
    // ------------------------------------------------------------------

    public function renderPanel(): void
    {
        // Security check
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('دسترسی مجاز نیست.', 'campaignchi'));
        }

        $currentUser = wp_get_current_user();
        $nonce       = wp_create_nonce('cmc_admin_nonce');
        $assetsUrl   = CMC_ASSETS_URL;
        $version     = CMC_VERSION;

        // Load the panel template
        // Template receives: $currentUser, $nonce, $assetsUrl, $version
        include CMC_PATH . 'templates/admin/panel.php';

        // CRITICAL: exit here — prevents WordPress from rendering its own page
        exit;
    }

    // ------------------------------------------------------------------
    // Enqueue Admin Assets
    // Only loads on our page (not on all wp-admin pages)
    // ------------------------------------------------------------------

    public function enqueueAssets(string $hook): void
    {
        // Only load on our menu page
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'cmc-base',
            CMC_ASSETS_URL . 'css/base.css',
            [],
            CMC_VERSION
        );

        wp_enqueue_style(
            'cmc-components',
            CMC_ASSETS_URL . 'css/components.css',
            ['cmc-base'],
            CMC_VERSION
        );

        wp_enqueue_style(
            'cmc-admin',
            CMC_ASSETS_URL . 'css/admin.css',
            ['cmc-components'],
            CMC_VERSION
        );

        // Tabler Icons (CDN)
        wp_enqueue_style(
            'cmc-icons',
            'https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css',
            [],
            null
        );

        // Vazirmatn Persian Font (CDN)
        wp_enqueue_style(
            'cmc-font',
            'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap',
            [],
            null
        );

        // JS
        wp_enqueue_script(
            'cmc-admin',
            CMC_ASSETS_URL . 'js/admin.js',
            [],
            CMC_VERSION,
            true   // Load in footer
        );

        // Pass PHP data to JS
        wp_localize_script('cmc-admin', 'CMC_DATA', [
            'nonce'    => wp_create_nonce('cmc_admin_nonce'),
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'restUrl'  => rest_url('campaignchi/v1/'),
            'version'  => CMC_VERSION,
            'currency' => get_woocommerce_currency_symbol(),
        ]);
    }

    // ------------------------------------------------------------------
    // SVG Menu Icon (inline base64 — WP standard approach)
    // ------------------------------------------------------------------

    private function getMenuIcon(): string
    {
        // Bolt/flash icon matching the design system
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
