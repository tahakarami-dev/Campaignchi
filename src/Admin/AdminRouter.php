<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin;

/**
 * Admin Router
 *
 * Maps the ?cmc_page= query parameter to a Page class.
 * No framework needed — simple array dispatch.
 *
 * URL pattern: /wp-admin/admin.php?page=campaignchi&cmc_page=campaigns
 *
 * @package Msi\Campaignchi\Admin
 */
class AdminRouter
{
    /** @var array<string, class-string> Route map: slug => Page class */
    private array $routes = [
        'dashboard'  => Pages\DashboardPage::class,
        'campaigns'  => Pages\CampaignsPage::class,
        'schedule'   => Pages\SchedulePage::class,
        'templates'  => Pages\TemplatesPage::class,
        'appearance' => Pages\AppearancePage::class,
        'settings'   => Pages\SettingsPage::class,
    ];

    /** @var string Default page slug */
    private const DEFAULT_PAGE = 'dashboard';

    // -------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------

    /**
     * Resolve the current page instance from the URL.
     * Falls back to DashboardPage if slug not found.
     *
     * @return Pages\AbstractPage
     */
    public function resolve(): Pages\AbstractPage
    {
        $slug = sanitize_key($_GET['cmc_page'] ?? self::DEFAULT_PAGE);

        $pageClass = $this->routes[$slug] ?? $this->routes[self::DEFAULT_PAGE];

        return new $pageClass();
    }

    /**
     * Get the current active page slug.
     */
    public function currentSlug(): string
    {
        $slug = sanitize_key($_GET['cmc_page'] ?? self::DEFAULT_PAGE);
        return array_key_exists($slug, $this->routes) ? $slug : self::DEFAULT_PAGE;
    }

    /**
     * Build a URL for a given panel page.
     *
     * @param string $slug  The cmc_page slug
     * @param array  $extra Additional query params
     */
    public static function url(string $slug, array $extra = []): string
    {
        return add_query_arg(
            array_merge(['page' => 'campaignchi', 'cmc_page' => $slug], $extra),
            admin_url('admin.php')
        );
    }
}
