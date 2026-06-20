<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics;

use Msi\Campaignchi\Analytics\Repositories\AnalyticsRepository;
use Msi\Campaignchi\Analytics\Services\AnalyticsService;
use Msi\Campaignchi\Campaign\Pricing\CampaignProductResolver;
use Msi\Campaignchi\Campaign\Pricing\CampaignResolver;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Core\Hooks;
use Msi\Campaignchi\Core\ServiceProvider;
use Msi\Campaignchi\Analytics\Controllers\ReportsAjaxController;
use Msi\Campaignchi\Analytics\Services\ReportService;

/**
 * Analytics Service Provider
 *
 * - ثبت AnalyticsRepository و AnalyticsService در کانتینر
 * - ثبت هوک فرانت برای شمارش بازدید (impression) محصولات کمپینی
 * - ثبت هوک‌های invalidation فوری برای کش‌های آماری داشبورد، تا داده‌ها
 *   بدون نیاز به منتظر ماندن برای انقضای TTL، بلافاصله به‌روز شوند
 *
 * ⚠️ این provider باید بعد از PricingServiceProvider در
 * Application::registerProviders() قرار بگیرد، چون به
 * CampaignRepository / CampaignResolver / CampaignProductResolver
 * که آنجا singleton می‌شوند نیاز دارد.
 *
 * @package Msi\Campaignchi\Analytics
 */
class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(
            AnalyticsRepository::class,
            fn() => new AnalyticsRepository()
        );

        $this->container->singleton(
            AnalyticsService::class,
            fn($c) => new AnalyticsService(
                $c->make(AnalyticsRepository::class),
                $c->make(CampaignRepository::class),
                $c->make(CampaignResolver::class),
                $c->make(CampaignProductResolver::class)
            )
        );

        $this->container->singleton(
            ReportService::class,
            fn($c) => new ReportService(
                $c->make(AnalyticsService::class),
                $c->make(CampaignRepository::class)
            )
        );

        $this->container->singleton(
            ReportsAjaxController::class,
            fn($c) => new ReportsAjaxController(
                $c->make(ReportService::class)
            )
        );
    }

    public function boot(): void
    {
        // ⚠️ BUG FIX (dashboard data lagging behind real orders):
        // Keep "today"'s dashboard stats accurate immediately after ANY
        // WooCommerce order status change — checkout on the frontend,
        // a manual status change from wp-admin's order list, or a
        // programmatic/REST status change all fire this hook. This must
        // be registered unconditionally (NOT behind the is_admin() guard
        // below), because marking an order "completed" from wp-admin is
        // itself an admin-context action that needs to trigger the same
        // cache flush.
        Hooks::action('woocommerce_order_status_changed', [$this, 'flushTodayCache']);

        // A campaign's own create/update/delete/status-change, or a
        // product's taxonomy/save event, can change which products are
        // considered "campaign products" for analytics purposes —
        // invalidate the cached candidates list used by every
        // analytics calculation so it gets rebuilt on the next request.
        Hooks::action('cmc_campaign_changed', [AnalyticsService::class, 'flushCampaignCandidatesCache']);
        Hooks::action('set_object_terms', [AnalyticsService::class, 'flushCampaignCandidatesCache']);
        Hooks::action('save_post_product', [AnalyticsService::class, 'flushCampaignCandidatesCache']);
        // Register the Reports CSV export handler (admin-ajax context).
        $this->container->make(ReportsAjaxController::class)->register();

        if (is_admin()) {
            return;
        }

        // شمارش بازدید برای هر محصول کمپینی که در شاپ یا تک‌محصول رندر می‌شود
        Hooks::action('woocommerce_before_shop_loop_item_title', [$this, 'trackImpression'], 5);
        Hooks::action('woocommerce_single_product_summary', [$this, 'trackImpression'], 5);
    }

    /**
     * Thin instance wrapper around AnalyticsService::flushTodayCache(),
     * needed because hooks are registered from this provider but the
     * cache-flush logic itself lives on the service.
     */
    public function flushTodayCache(): void
    {
        $this->container->make(AnalyticsService::class)->flushTodayCache();
    }

    /**
     * اگر محصول جاری زیر یک کمپین زنده باشد، یک بازدید برای آن کمپین ثبت می‌کند.
     */
    public function trackImpression(): void
    {
        global $product;

        if (!($product instanceof \WC_Product)) {
            return;
        }

        $productId = $product->get_parent_id() ?: $product->get_id();
        $campaign  = $this->container->make(CampaignResolver::class)->findForProduct($productId);

        if (!$campaign) {
            return;
        }

        $this->container->make(AnalyticsRepository::class)->recordImpression((int) $campaign['id']);
    }
}
