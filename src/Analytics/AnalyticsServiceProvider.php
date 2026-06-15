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

/**
 * Analytics Service Provider
 *
 * - ثبت AnalyticsRepository و AnalyticsService در کانتینر
 * - ثبت هوک فرانت برای شمارش بازدید (impression) محصولات کمپینی
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
    }

    public function boot(): void
    {
        if (is_admin()) {
            return;
        }

        // شمارش بازدید برای هر محصول کمپینی که در شاپ یا تک‌محصول رندر می‌شود
        Hooks::action('woocommerce_before_shop_loop_item_title', [$this, 'trackImpression'], 5);
        Hooks::action('woocommerce_single_product_summary', [$this, 'trackImpression'], 5);
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