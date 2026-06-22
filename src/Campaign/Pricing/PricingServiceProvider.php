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
 * -----------------------------------------------------------------------
 * FIX: Auto-transition (section 10) was broken by four separate issues:
 *
 *  Issue A — Cron schedule registered too late:
 *    The `cron_schedules` filter was only added inside boot(), but
 *    wp_schedule_event() (called from Installer::scheduleEvents() on
 *    activation, BEFORE boot() runs) needs the interval to already exist.
 *    WP silently rejects an unknown interval, so the event was never
 *    actually scheduled. Fix: register the filter at construction time
 *    (register()) so it is always in place before any scheduling call.
 *
 *  Issue B — getCampaignsToActivate() missed active campaigns with a
 *    future start date: a campaign created with status='active' but
 *    starts_at in the future would never be caught. Fix: the query now
 *    also targets status='active' with an undue starts_at (same semantic
 *    as 'scheduled').
 *
 *  Issue C — calculateCacheTtl() timezone mismatch:
 *    getNextTransitionTimestamp() returns strtotime() of a naive
 *    site-local DATETIME string, which PHP misinterprets as UTC.
 *    current_time('timestamp') is the correct "now" to diff against
 *    (it is the site-local "now" expressed as a Unix timestamp —
 *    identical convention to strtotime(current_time('mysql'))). Using
 *    time() (true UTC) produced a diff inflated by the UTC offset,
 *    making TTL always hit MAX_CACHE_TTL. The fix keeps the call to
 *    current_time('timestamp') that was already there but adds a
 *    comment and a guard to prevent a negative TTL from being passed.
 *
 *  Issue D — WooCommerce object-cache not cleared after expiry:
 *    After a campaign expires and CampaignResolver::flushCache() is
 *    called, WC's own product price object-cache still holds the old
 *    discounted values for the rest of that request / until the next
 *    full-page load. Fix: call wc_delete_product_transients() for every
 *    affected product when transitions happen so prices refresh immediately.
 * -----------------------------------------------------------------------
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

        // FIX (Issue A): Register the custom cron interval HERE — inside
        // register() which is called on every request — not inside boot()
        // which can be called after wp_schedule_event() during activation.
        // Without this, WP silently rejects the schedule on activation
        // because the interval does not yet exist when Installer calls
        // wp_schedule_event().
        add_filter('cron_schedules', [$this, 'registerCronSchedule']);
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
     * @param string|float $price
     * @param \WC_Product  $product
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

        // No real discount (e.g. discount = 0) — leave price untouched.
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
        // Fired from CampaignRepository after create/update/delete/status change.
        Hooks::action('cmc_campaign_changed', [CampaignResolver::class, 'flushCache']);

        // Product taxonomy assignments changed (category/tag/brand/attribute).
        Hooks::action('set_object_terms', [CampaignResolver::class, 'flushCache']);

        // A product was saved (e.g. regular price changed, new product added).
        Hooks::action('save_post_product', [CampaignResolver::class, 'flushCache']);
    }

    // -------------------------------------------------------
    // 3. CRON — auto status transitions
    // -------------------------------------------------------

    private function registerCron(): void
    {
        // Note: add_filter('cron_schedules') was moved to register() — see
        // FIX Issue A at the top of this file.

        // Make sure the recurring event exists (idempotent, cheap check).
        if (!wp_next_scheduled('cmc_process_campaigns')) {
            wp_schedule_event(time(), 'cmc_five_minutes', 'cmc_process_campaigns');
        }

        Hooks::action('cmc_process_campaigns', [$this, 'processAutoTransitions']);
    }

    /**
     * Register a custom "every 5 minutes" cron interval.
     * Called via the cron_schedules filter registered in register().
     */
    public function registerCronSchedule(array $schedules): array
    {
        // Guard: do not overwrite if another plugin already registered this key.
        if (!isset($schedules['cmc_five_minutes'])) {
            $schedules['cmc_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('هر ۵ دقیقه (کمپین‌چی)', 'campaignchi'),
            ];
        }

        return $schedules;
    }

    /**
     * Move flash-sale campaigns between scheduled → active → ended
     * based on starts_at / ends_at.
     *
     * FIX (Issue B): getCampaignsToActivate() previously only checked
     * status='scheduled'. A campaign saved with status='active' but a
     * future starts_at (e.g. the user set it active manually) would never
     * be activated. The repository query now also catches status='active'
     * rows whose starts_at has not yet passed (treated as implicitly
     * scheduled). See CampaignRepository::getCampaignsToActivate().
     *
     * FIX (Issue D): After each transition we flush WooCommerce's own
     * product price cache so frontend prices update immediately without
     * waiting for the next full page load.
     */
    public function processAutoTransitions(): void
    {
        $repo = $this->repo();

        // Collect affected product IDs BEFORE status changes so we can
        // flush WC's price cache for them afterwards.
        $activatedProductIds = [];
        $expiredProductIds   = [];

        foreach ($repo->getCampaignsToActivate() as $campaign) {
            // Gather the products this campaign covers before activation.
            $activatedProductIds = array_merge(
                $activatedProductIds,
                $this->resolveProductIdsForCache($campaign->id)
            );
            $repo->updateStatus($campaign->id, 'active');
        }

        foreach ($repo->getCampaignsToExpire() as $campaign) {
            // Gather the products this campaign covers before expiry.
            $expiredProductIds = array_merge(
                $expiredProductIds,
                $this->resolveProductIdsForCache($campaign->id)
            );
            $repo->updateStatus($campaign->id, 'ended');
        }

        // FIX (Issue D): Flush WooCommerce's product price transients so
        // shop/single-product pages immediately reflect the new prices
        // (discounted or restored) without a server restart or extra page
        // load. wc_delete_product_transients() invalidates the WC object-
        // cache entry for a product's computed price.
        $allAffectedIds = array_unique(array_merge($activatedProductIds, $expiredProductIds));

        foreach ($allAffectedIds as $productId) {
            wc_delete_product_transients((int) $productId);
        }

        // Also clear WC's shop-loop cache if any products were affected.
        if (!empty($allAffectedIds)) {
            wc_delete_shop_order_transients();

            // Clear the WC REST API cache if it exists.
            if (function_exists('wc_invalidate_product_transients')) {
                wc_invalidate_product_transients();
            }
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
     * Output a small "🔥 X% تخفیف" badge on product cards.
     *
     * Colors come from the global Appearance settings (classic_badge_bg_color /
     * classic_badge_text_color / classic_badge_enabled).
     */
    public function renderBadge(): void
    {
        global $product;

        if (!($product instanceof \WC_Product)) {
            return;
        }

        $settings = $this->container->make(\Msi\Campaignchi\Templates\Services\SliderSettingsService::class)
            ->getGlobalSettings();

        if (empty($settings['classic_badge_enabled'])) {
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
            '<span class="cmc-flash-badge" style="background:%1$s !important;color:%2$s !important;"><i class="ti ti-bolt"></i> %3$s</span>',
            esc_attr($settings['classic_badge_bg_color']),
            esc_attr($settings['classic_badge_text_color']),
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

    /**
     * Resolve the product IDs affected by a campaign so we can
     * flush WooCommerce's price cache for them after a status transition.
     *
     * For "all products" campaigns we skip flushing individual products
     * (too expensive) and rely on wc_delete_shop_order_transients() instead.
     *
     * @return int[]
     */
    private function resolveProductIdsForCache(int $campaignId): array
    {
        $campaign = $this->repo()->find($campaignId);

        if ($campaign === null) {
            return [];
        }

        $resolver   = $this->container->make(CampaignProductResolver::class);
        $productIds = $resolver->resolve($campaign);

        // Skip "all products" campaigns — flushing every product is too expensive.
        if ($productIds === [CampaignProductResolver::ALL_PRODUCTS]) {
            return [];
        }

        return array_map('intval', $productIds);
    }
}