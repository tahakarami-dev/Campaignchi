<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Pricing;

use Msi\Campaignchi\Core\ServiceProvider;
use Msi\Campaignchi\Core\Hooks;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Helpers\JalaliHelper;

/**
 * Pricing Service Provider
 *
 * Wires the campaign pricing engine into WooCommerce:
 *  1. Filters product/variation prices on-the-fly (real, dynamic discounts)
 *  2. Invalidates the pricing cache when campaigns or taxonomies change
 *  3. Registers the 5-minute cron schedule + auto status transitions
 *     (scheduled → active → ended for flash sales)
 *  4. Renders a small flash badge on shop/product pages
 *
 * @package Msi\Campaignchi\Campaign\Pricing
 */
class PricingServiceProvider extends ServiceProvider
{
    // -------------------------------------------------------
    // REGISTER
    // -------------------------------------------------------

    public function register(): void
    {
        $this->container->singleton(
            CampaignRepository::class,
            fn() => new CampaignRepository()
        );

        $this->container->singleton(
            CampaignProductResolver::class,
            fn($c) => new CampaignProductResolver($c->make(CampaignRepository::class))
        );

        $this->container->singleton(
            CampaignResolver::class,
            fn($c) => new CampaignResolver(
                $c->make(CampaignRepository::class),
                $c->make(CampaignProductResolver::class)
            )
        );
    }

    // -------------------------------------------------------
    // BOOT
    // -------------------------------------------------------

    public function boot(): void
    {
        $this->registerPriceFilters();
        $this->registerCacheInvalidation();
        $this->registerCron();
        $this->registerFrontendBadge();
    }

    // -------------------------------------------------------
    // 1. PRICE FILTERS — the actual discount engine
    // -------------------------------------------------------

    private function registerPriceFilters(): void
    {
        foreach (
            [
                'woocommerce_product_get_price',
                'woocommerce_product_get_sale_price',
                'woocommerce_product_variation_get_price',
                'woocommerce_product_variation_get_sale_price',
            ] as $hook
        ) {
            Hooks::filter($hook, [$this, 'filterPrice'], 99, 2);
        }

        Hooks::filter('woocommerce_product_is_on_sale', [$this, 'filterIsOnSale'], 99, 2);
    }

    /**
     * Replace the price with the campaign-discounted price.
     *
     * @param string|float     $price
     * @param \WC_Product      $product
     * @return string|float
     */
    public function filterPrice($price, $product)
    {
        if ($price === '' || $price === null || !($product instanceof \WC_Product)) {
            return $price;
        }

        $productId = $product->get_parent_id() ?: $product->get_id();
        $campaign  = $this->resolver()->findForProduct($productId);

        if (!$campaign) {
            return $price;
        }

        $regular = (float) $product->get_regular_price();

        if ($regular <= 0) {
            return $price;
        }

        $final = PriceCalculator::apply($regular, (float) $campaign['discount'], $campaign['discount_type']);

        // No real discount (e.g. discount = 0) — leave price untouched
        if ($final >= $regular) {
            return $price;
        }

        return (string) $final;
    }

    /**
     * Force "on sale" state so price strike-through / badges show correctly.
     */
    public function filterIsOnSale($onSale, $product)
    {
        if (!($product instanceof \WC_Product)) {
            return $onSale;
        }

        $productId = $product->get_parent_id() ?: $product->get_id();
        $campaign  = $this->resolver()->findForProduct($productId);

        if (!$campaign) {
            return $onSale;
        }

        $regular = (float) $product->get_regular_price();
        $final   = PriceCalculator::apply($regular, (float) $campaign['discount'], $campaign['discount_type']);

        return $final < $regular ? true : $onSale;
    }

    // -------------------------------------------------------
    // 2. CACHE INVALIDATION
    // -------------------------------------------------------

    private function registerCacheInvalidation(): void
    {
        // Fired manually from CampaignRepository after create/update/delete/status change
        Hooks::action('cmc_campaign_changed', [CampaignResolver::class, 'flushCache']);

        // Product taxonomy assignments changed (category/tag/brand/attribute)
        Hooks::action('set_object_terms', [CampaignResolver::class, 'flushCache']);

        // A product was saved (e.g. regular price changed, new product added)
        Hooks::action('save_post_product', [CampaignResolver::class, 'flushCache']);
    }

    // -------------------------------------------------------
    // 3. CRON — auto status transitions
    // -------------------------------------------------------

    private function registerCron(): void
    {
        Hooks::filter('cron_schedules', [$this, 'registerCronSchedule']);

        // Make sure the recurring event exists (idempotent, cheap check)
        if (!wp_next_scheduled('cmc_process_campaigns')) {
            wp_schedule_event(time(), 'cmc_five_minutes', 'cmc_process_campaigns');
        }

        Hooks::action('cmc_process_campaigns', [$this, 'processAutoTransitions']);
    }

    /**
     * Register a custom "every 5 minutes" cron interval.
     */
    public function registerCronSchedule(array $schedules): array
    {
        $schedules['cmc_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('هر ۵ دقیقه (کمپین‌چی)', 'campaignchi'),
        ];

        return $schedules;
    }

    /**
     * Move flash-sale campaigns between scheduled → active → ended
     * based on starts_at / ends_at.
     *
     * NOTE: this only keeps the admin-facing `status` field accurate.
     * The actual pricing engine (CampaignResolver) computes "live" state
     * from dates directly, so prices stay correct even between cron runs.
     */
    public function processAutoTransitions(): void
    {
        $repo = $this->repo();

        foreach ($repo->getCampaignsToActivate() as $campaign) {
            $repo->updateStatus($campaign->id, 'active');
        }

        foreach ($repo->getCampaignsToExpire() as $campaign) {
            $repo->updateStatus($campaign->id, 'ended');
        }
    }

    // -------------------------------------------------------
    // 4. FRONTEND BADGE
    // -------------------------------------------------------

    private function registerFrontendBadge(): void
    {
        if (is_admin()) {
            return;
        }

        Hooks::action('woocommerce_before_shop_loop_item_title', [$this, 'renderBadge'], 5);
        Hooks::action('woocommerce_single_product_summary', [$this, 'renderBadge'], 5);
    }

    /**
     * Output a small "🔥 X% تخفیف" badge if the current global $product
     * is covered by a live campaign.
     *
     * The percentage is ALWAYS shown — even for fixed-amount discounts,
     * which are converted to a percentage dynamically based on THIS
     * product's regular price (a fixed amount means a different %
     * on every product).
     */
    public function renderBadge(): void
    {
        global $product;

        if (!($product instanceof \WC_Product)) {
            return;
        }

        $productId = $product->get_parent_id() ?: $product->get_id();
        $campaign  = $this->resolver()->findForProduct($productId);

        if (!$campaign) {
            return;
        }

        $regular = (float) $product->get_regular_price();
        $final   = PriceCalculator::apply($regular, (float) $campaign['discount'], $campaign['discount_type']);

        if ($final >= $regular) {
            return;
        }

        $percent = PriceCalculator::percentOff($regular, $final);

        if ($percent <= 0) {
            return;
        }

        $label = JalaliHelper::toPersianNums((string) $percent) . '٪';

        printf(
            '<span class="cmc-flash-badge"><i class="ti ti-bolt"></i> %s</span>',
            esc_html(sprintf(__('%s تخفیف', 'campaignchi'), $label))
        );
    }

    // -------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------

    private function resolver(): CampaignResolver
    {
        return $this->container->make(CampaignResolver::class);
    }

    private function repo(): CampaignRepository
    {
        return $this->container->make(CampaignRepository::class);
    }
}
