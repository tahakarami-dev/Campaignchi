<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin;

use Msi\Campaignchi\Core\ServiceProvider;
use Msi\Campaignchi\Core\Hooks;

/**
 * Admin Service Provider
 *
 * Responsibilities:
 *  - Register "کمپین‌چی" menu item in WP admin sidebar
 *  - Intercept the page load and render our fully custom SaaS UI
 *  - Suppress all WordPress admin chrome (topbar, sidebar, notices)
 *  - Enqueue our design system assets
 *
 * The trick: we register a normal WP menu page, but when it loads
 * we output our own full-page HTML and exit — WordPress never
 * renders its own admin shell.
 *
 * @package Msi\Campaignchi\Admin
 */
class AdminServiceProvider extends ServiceProvider
{
    /** @var string WP menu slug */
    private const MENU_SLUG = 'campaignchi';

    /** @var string Capability required to access panel */
    private const CAPABILITY = 'manage_options';

    // -------------------------------------------------------
    // Register
    // -------------------------------------------------------

    public function register(): void
    {
        // Bind AdminRouter into container
        $this->container->singleton(
            AdminRouter::class,
            fn($c) => new AdminRouter()
        );
    }

    // -------------------------------------------------------
    // Boot
    // -------------------------------------------------------

    public function boot(): void
    {
        // Register WP admin menu item
        Hooks::action('admin_menu', [$this, 'registerMenu']);

        // Enqueue assets only on our page
        Hooks::action('admin_enqueue_scripts', [$this, 'enqueueAssets'], 10, 1);

        // Full-page takeover: suppress WP chrome before headers sent
        Hooks::action('admin_init', [$this, 'maybeRenderPanel']);
    }

    // -------------------------------------------------------
    // Menu Registration
    // -------------------------------------------------------

    /**
     * Register "کمپین‌چی" as a top-level WP admin menu item.
     * The callback is a dummy — real render happens in maybeRenderPanel().
     */
    public function registerMenu(): void
    {
        add_menu_page(
            __('کمپین‌چی', 'campaignchi'),       // Page title
            __('کمپین‌چی', 'campaignchi'),       // Menu label
            self::CAPABILITY,                     // Capability
            self::MENU_SLUG,                      // Slug
            '__return_null',                      // Dummy callback (we intercept earlier)
            $this->getMenuIcon(),                 // SVG icon
            25                                    // Position (after Comments)
        );
    }

    // -------------------------------------------------------
    // Full-Page Panel Takeover
    // -------------------------------------------------------

    /**
     * Intercept admin_init to detect our page, then:
     *  1. Suppress all WP admin HTML
     *  2. Output our full custom panel
     *  3. exit — WP never renders its shell
     *
     * This runs before any output, so we have full control.
     */
    public function maybeRenderPanel(): void
    {
        // Only act on our menu page
        $page = sanitize_key($_GET['page'] ?? '');

        if ($page !== self::MENU_SLUG) {
            return;
        }

        // Capability check
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(
                esc_html__('دسترسی مجاز نیست.', 'campaignchi'),
                403
            );
        }

        // Remove default WP admin notices so they don't pollute our UI
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('network_admin_notices');

        // Render our custom panel (full HTML page)
        $router = $this->container->make(AdminRouter::class);
        $layout = new Layouts\PanelLayout($router);
        $layout->render();

        // Stop WordPress from rendering anything after this
        exit;
    }

    // -------------------------------------------------------
    // Assets
    // -------------------------------------------------------

    /**
     * Enqueue our design system CSS/JS only on our page.
     *
     * @param string $hook Current admin page hook
     */
    public function enqueueAssets(string $hook): void
    {
        // WP generates hook as "toplevel_page_{slug}"
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        // Assets are enqueued but since we exit early in maybeRenderPanel,
        // we load them manually inside PanelLayout::render() via direct <link>/<script> tags.
        // This hook is kept for fallback / future partial-page use.
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /**
     * Return base64-encoded SVG icon for the WP admin menu item.
     * Uses the brand purple (#6C47FF) bolt icon.
     */
    private function getMenuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a78bff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
