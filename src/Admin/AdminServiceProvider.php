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
 *  - Intercept the page load and render fully custom SaaS panel
 *  - Suppress WP admin chrome (sidebar, footer, notices)
 *  - Register all admin AJAX controllers
 *
 * @package Msi\Campaignchi\Admin
 */
class AdminServiceProvider extends ServiceProvider
{
    private const MENU_SLUG   = 'campaignchi';
    private const CAPABILITY  = 'manage_options';

    // -------------------------------------------------------
    // Register — bind services into container
    // -------------------------------------------------------

    public function register(): void
    {
        // AdminRouter
        $this->container->singleton(
            AdminRouter::class,
            fn($c) => new AdminRouter()
        );

        // CampaignService + Repository
        $this->container->singleton(
            \Msi\Campaignchi\Campaign\Services\CampaignService::class,
            fn($c) => new \Msi\Campaignchi\Campaign\Services\CampaignService(
                new \Msi\Campaignchi\Campaign\Repositories\CampaignRepository()
            )
        );

        // CampaignController
        $this->container->singleton(
            Controllers\CampaignController::class,
            fn($c) => new Controllers\CampaignController(
                $c->make(\Msi\Campaignchi\Campaign\Services\CampaignService::class)
            )
        );
    }

    // -------------------------------------------------------
    // Boot — register WP hooks
    // -------------------------------------------------------

    public function boot(): void
    {
        // WP admin menu
        Hooks::action('admin_menu', [$this, 'registerMenu']);

        // Full-page takeover (runs before any output)
        Hooks::action('admin_init', [$this, 'maybeRenderPanel']);

        // Keep brand colors in WP menu icon
        Hooks::action('admin_head', [$this, 'injectMenuIconStyle']);

        // Register all AJAX controllers
        $controller = $this->container->make(Controllers\CampaignController::class);
        $controller->register();
    }

    // -------------------------------------------------------
    // Menu
    // -------------------------------------------------------

    public function registerMenu(): void
    {
        add_menu_page(
            __('کمپین‌چی', 'campaignchi'),
            __('کمپین‌چی', 'campaignchi'),
            self::CAPABILITY,
            self::MENU_SLUG,
            '__return_null',
            $this->getMenuIcon(),
            25
        );
    }

    // -------------------------------------------------------
    // Full-Page Panel Takeover
    // -------------------------------------------------------

    public function maybeRenderPanel(): void
    {
        $page = sanitize_key($_GET['page'] ?? '');

        if ($page !== self::MENU_SLUG) {
            return;
        }

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی مجاز نیست.', 'campaignchi'), 403);
        }

        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('network_admin_notices');

        $router = $this->container->make(AdminRouter::class);
        $layout = new Layouts\PanelLayout($router);
        $layout->render();

        exit;
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
        return CMC_URL . 'assets/images/logo.png';
    }

    /**
     * Inject CSS to prevent WordPress from greyscaling our menu icon.
     * WP applies opacity + filter on menu icons by default.
     */
    public function injectMenuIconStyle(): void
    {
?>
        <style>
            /* Remove WP greyscale filter from Campaignchi menu icon */
            #adminmenu #toplevel_page_campaignchi .wp-menu-image img {
                opacity: 1 !important;
                filter: none !important;
                width: 30px !important;
                height: 30px !important;
                margin-top: -7px;
                margin-right: 7px;
            }
            #adminmenu #toplevel_page_campaignchi .wp-menu-name{
                margin-right: 10px !important;
            }

            /* Hover / active — keep colors, just slight brightness */
            #adminmenu #toplevel_page_campaignchi:hover .wp-menu-image img,
            #adminmenu #toplevel_page_campaignchi.wp-has-current-submenu .wp-menu-image img,
            #adminmenu #toplevel_page_campaignchi.current .wp-menu-image img {
                opacity: 1 !important;
                filter: brightness(1.1) !important;
            }
        </style>
<?php
    }
}
