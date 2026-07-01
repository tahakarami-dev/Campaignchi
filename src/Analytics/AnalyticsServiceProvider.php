<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics;

use Msi\Campaignchi\Analytics\Repositories\AnalyticsRepository;
use Msi\Campaignchi\Analytics\Repositories\CampaignSalesRepository;
use Msi\Campaignchi\Analytics\Services\AnalyticsService;
use Msi\Campaignchi\Analytics\Services\CampaignSalesRecorder;
use Msi\Campaignchi\Campaign\Pricing\CampaignProductResolver;
use Msi\Campaignchi\Campaign\Pricing\CampaignResolver;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Core\Hooks;
use Msi\Campaignchi\Core\ServiceProvider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analytics Service Provider
 *
 * - Registers analytics repositories/services in the container.
 * - Registers the frontend impression-tracking hook.
 * - Registers dashboard cache-invalidation hooks.
 * - ⚠️ NEW: registers CampaignSalesRecorder — the event-log writer that
 * ⚠️ Must be registered AFTER PricingServiceProvider in
 * Application::registerProviders(), since it depends on
 * CampaignRepository / CampaignResolver / CampaignProductResolver
 * (singletons defined there).
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

        // NEW: accurate campaign-sales event log.
        $this->container->singleton(
            CampaignSalesRepository::class,
            fn() => new CampaignSalesRepository()
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

        // NEW: the recorder that snapshots campaign sales at order time.
        $this->container->singleton(
            CampaignSalesRecorder::class,
            fn($c) => new CampaignSalesRecorder(
                $c->make(CampaignResolver::class),
                $c->make(CampaignSalesRepository::class)
            )
        );
    }

    public function boot(): void
    {
        // Keep "today"'s dashboard stats fresh on any order status change.
        Hooks::action('woocommerce_order_status_changed', [$this, 'flushTodayCache']);

        // Campaign/product changes invalidate the cached analytics candidates.
        Hooks::action('cmc_campaign_changed', [AnalyticsService::class, 'flushCampaignCandidatesCache']);
        Hooks::action('set_object_terms', [AnalyticsService::class, 'flushCampaignCandidatesCache']);
        Hooks::action('save_post_product', [AnalyticsService::class, 'flushCampaignCandidatesCache']);

        // ⚠️ NEW: register the campaign-sales recorder UNCONDITIONALLY (not
        // behind is_admin()), because checkout and order-status hooks fire
        // in both frontend and admin contexts.
        $this->container->make(CampaignSalesRecorder::class)->register();

        if (is_admin()) {
            return;
        }

        // Impression tracking for campaign products on shop/single pages.
        Hooks::action('woocommerce_before_shop_loop_item_title', [$this, 'trackImpression'], 5);
        Hooks::action('woocommerce_single_product_summary', [$this, 'trackImpression'], 5);
    }

    public function flushTodayCache(): void
    {
        $this->container->make(AnalyticsService::class)->flushTodayCache();
    }

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