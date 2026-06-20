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
    private const MENU_SLUG  = 'campaignchi';
    private const CAPABILITY = 'manage_options';

    // -------------------------------------------------------
    // Register — bind services into container
    // -------------------------------------------------------

    public function register(): void
    {
        // Admin router
        $this->container->singleton(
            AdminRouter::class,
            fn ($c) => new AdminRouter()
        );

        // Campaign service + repository
        $this->container->singleton(
            \Msi\Campaignchi\Campaign\Repositories\CampaignRepository::class,
            fn ($c) => new \Msi\Campaignchi\Campaign\Repositories\CampaignRepository()
        );

        $this->container->singleton(
            \Msi\Campaignchi\Campaign\Services\CampaignService::class,
            fn ($c) => new \Msi\Campaignchi\Campaign\Services\CampaignService(
                $c->make(\Msi\Campaignchi\Campaign\Repositories\CampaignRepository::class)
            )
        );

        // Campaign AJAX controller
        $this->container->singleton(
            Controllers\CampaignController::class,
            fn ($c) => new Controllers\CampaignController(
                $c->make(\Msi\Campaignchi\Campaign\Services\CampaignService::class)
            )
        );

        // Settings AJAX controller — no dependencies beyond WP globals
        $this->container->singleton(
            Controllers\SettingsAjaxController::class,
            fn ($c) => new Controllers\SettingsAjaxController()
        );
    }

    // -------------------------------------------------------
    // Boot — register WP hooks
    // -------------------------------------------------------

    public function boot(): void
    {
        // WP admin menu entry
        Hooks::action('admin_menu', [$this, 'registerMenu']);

        // Full-page panel takeover (runs before any output)
        Hooks::action('admin_init', [$this, 'maybeRenderPanel']);

        // Keep brand colours in WP menu icon
        Hooks::action('admin_head', [$this, 'injectMenuIconStyle']);

        // Register AJAX controllers
        $this->container->make(Controllers\CampaignController::class)->register();
        $this->container->make(Controllers\SettingsAjaxController::class)->register();
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
     * Return the plugin logo URL as the WP admin menu icon.
     */
    private function getMenuIcon(): string
    {
        return CMC_URL . 'assets/images/logo.png';
    }

    /**
     * Inject CSS to prevent WordPress from grey-scaling our menu icon.
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
            #adminmenu #toplevel_page_campaignchi .wp-menu-name {
                margin-right: 10px !important;
            }
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