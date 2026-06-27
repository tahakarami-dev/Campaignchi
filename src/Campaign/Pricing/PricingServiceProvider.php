<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Pricing;

use Msi\Campaignchi\Admin\Pages\SettingsPage;
use Msi\Campaignchi\Core\ServiceProvider;
use Msi\Campaignchi\Core\Hooks;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Helpers\JalaliHelper;

/**
 * Pricing Service Provider
 *
 * Wires the campaign pricing engine into WooCommerce.
 *
 * -----------------------------------------------------------------------
 * Section 10 — Auto-transition logic (cron-driven)
 *
 *  Execution order inside processAutoTransitions():
 *    1. EXPIRE first  — active/scheduled campaigns past their ends_at.
 *    2. ACTIVATE next — scheduled campaigns whose starts_at has arrived.
 *
 *  Order matters: if both conditions are true (campaign expired before
 *  the cron ran), expiry wins. The getCampaignsToActivate() query already
 *  guards against this with `ends_at >= now`, but explicit ordering adds
 *  an extra layer of safety and clarity.
 *
 *  After each batch, WooCommerce product price transients are flushed for
 *  all affected products so shop/single-product pages reflect new prices
 *  immediately without waiting for the next page cache warm-up.
 *
 *  Cron schedule:
 *    - "cmc_five_minutes" interval (300 s) registered in register() so it
 *      exists at plugin activation time (before boot() runs).
 *    - Hook: 'cmc_process_campaigns' → processAutoTransitions()
 *
 *  Expiry target status:
 *    Read from SettingsPage::getCampaign()['auto_expire_status'].
 *    Allowed values: 'ended' | 'draft'. Default: 'ended'.
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

        // Register the custom cron interval in register() — not boot() — so
        // it is available at plugin activation time before hooks fire.
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
    // 1. PRICE FILTERS
    // -------------------------------------------------------

    /**
     * Attach the campaign price override to all WooCommerce price hooks.
     */
    private function registerPriceFilters(): void
    {
        foreach ([
            'woocommerce_product_get_price',
            'woocommerce_product_get_sale_price',
            'woocommerce_product_variation_get_price',
            'woocommerce_product_variation_get_sale_price',
        ] as $hook) {
            Hooks::filter($hook, [$this, 'filterPrice'], 99, 2);
        }

        Hooks::filter('woocommerce_product_is_on_sale', [$this, 'filterIsOnSale'], 99, 2);
    }

    /**
     * Override product price if a live campaign covers this product.
     *
     * @param string|float $price   Original WooCommerce price.
     * @param \WC_Product  $product WooCommerce product object.
     * @return string|float Discounted price, or original if no campaign applies.
     */
    public function filterPrice($price, $product)
    {
        // Skip empty prices and non-product objects.
        if ($price === '' || $price === null || !($product instanceof \WC_Product)) {
            return $price;
        }

        // Resolve parent ID for variations.
        $productId = $product->get_parent_id() ?: $product->get_id();
        $campaign  = $this->resolver()->findForProduct($productId);

        if (!$campaign) {
            return $price;
        }

        $regular = (float) $product->get_regular_price();

        // Products without a regular price cannot be discounted.
        if ($regular <= 0) {
            return $price;
        }

        $final = PriceCalculator::apply($regular, (float) $campaign['discount'], $campaign['discount_type']);

        // Do not return a higher price than the original (safety guard).
        if ($final >= $regular) {
            return $price;
        }

        return (string) $final;
    }

    /**
     * Force "on sale" state so WooCommerce renders strike-through prices.
     *
     * @param bool         $onSale  Current on-sale flag.
     * @param \WC_Product  $product WooCommerce product object.
     * @return bool
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

    /**
     * Register hooks that should bust the campaign resolver's cache.
     */
    private function registerCacheInvalidation(): void
    {
        Hooks::action('cmc_campaign_changed', [CampaignResolver::class, 'flushCache']);
        Hooks::action('set_object_terms',     [CampaignResolver::class, 'flushCache']);
        Hooks::action('save_post_product',    [CampaignResolver::class, 'flushCache']);
    }

    // -------------------------------------------------------
    // 3. CRON
    // -------------------------------------------------------

    /**
     * Schedule the campaign auto-transition cron job.
     *
     * Uses the 'cmc_campaign_cron' schedule whose interval is read live from
     * the admin settings via registerCronSchedule(). Existing installs that
     * were scheduled under the legacy 'cmc_five_minutes' interval are
     * automatically migrated to 'cmc_campaign_cron' on the next boot.
     *
     * BUG FIX: Previously, this method only checked wp_next_scheduled() and
     * skipped scheduling if ANY event was found — even legacy events using the
     * fixed 'cmc_five_minutes' schedule. This meant admin interval changes had
     * no effect until settings were manually re-saved.
     *
     * Now, we also inspect the schedule name of the existing event and replace
     * it when it differs from the canonical 'cmc_campaign_cron'.
     */
    private function registerCron(): void
    {
        $hook         = 'cmc_process_campaigns';
        $targetSched  = 'cmc_campaign_cron';
        $legacySched  = 'cmc_five_minutes';

        $nextTimestamp = wp_next_scheduled($hook);

        if ($nextTimestamp !== false) {
            // Retrieve the full cron array to inspect the schedule name used.
            $cronArray     = _get_cron_array();
            $existingSched = null;

            // Walk the cron array to find which schedule name was used.
            foreach ($cronArray as $schedEvents) {
                if (isset($schedEvents[$hook])) {
                    // Each hook entry is keyed by md5(serialize(args)).
                    $hookEntry     = reset($schedEvents[$hook]);
                    $existingSched = $hookEntry['schedule'] ?? null;
                    break;
                }
            }

            // If the existing event already uses the canonical schedule, do nothing.
            if ($existingSched === $targetSched) {
                Hooks::action($hook, [$this, 'processAutoTransitions']);
                return;
            }

            // Otherwise (legacy 'cmc_five_minutes' or unknown) — replace it.
            wp_unschedule_event($nextTimestamp, $hook);
        }

        wp_schedule_event(time(), $targetSched, $hook);
        Hooks::action($hook, [$this, 'processAutoTransitions']);
    }

    /**
     * Register Campaignchi's custom cron intervals with WordPress.
     *
     * 'cmc_campaign_cron' is the active, settings-driven interval (5/10/15/30
     * minutes). 'cmc_five_minutes' is kept for backward compatibility with
     * events scheduled by older versions / the installer.
     *
     * Registered via the 'cron_schedules' filter in register() so the
     * intervals are always known before activation hooks or rescheduling fire.
     *
     * @param array $schedules Existing WP cron schedule definitions.
     * @return array Modified schedules with our custom entries added.
     */
    public function registerCronSchedule(array $schedules): array
    {
        // Active interval — read from settings and clamped to allowed values.
        $minutes = (int) (SettingsPage::getCampaign()['cron_interval_minutes'] ?? 5);
        if (!in_array($minutes, [5, 10, 15, 30], true)) {
            $minutes = 5;
        }

        $schedules['cmc_campaign_cron'] = [
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display'  => sprintf(__('هر %d دقیقه (کمپین‌چی)', 'campaignchi'), $minutes),
        ];

        // Legacy interval — keep registered so pre-existing events still recur.
        if (!isset($schedules['cmc_five_minutes'])) {
            $schedules['cmc_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('هر ۵ دقیقه (کمپین‌چی)', 'campaignchi'),
            ];
        }

        return $schedules;
    }

    /**
     * Transition campaigns between statuses based on their date boundaries.
     *
     * EXECUTION ORDER (important):
     *   1. EXPIRE  — campaigns past ends_at  → 'ended' or 'draft'
     *   2. ACTIVATE — campaigns past starts_at → 'active'
     *
     * Expiry runs first so that a campaign which started and ended
     * between two cron cycles is expired rather than briefly activated.
     * getCampaignsToActivate() also guards this with `ends_at >= now`,
     * but running expiry first is the explicit and canonical approach.
     *
     * After transitions, WooCommerce price transients are flushed for all
     * affected products so frontend prices update without a cache warm-up.
     */
    public function processAutoTransitions(): void
    {
        $repo = $this->repo();

        // Read the admin-configured expiry target status.
        $campaignSettings = SettingsPage::getCampaign();
        $expireStatus     = in_array($campaignSettings['auto_expire_status'] ?? '', ['ended', 'draft'], true)
            ? $campaignSettings['auto_expire_status']
            : 'ended'; // Safe default.

        $expiredProductIds   = [];
        $activatedProductIds = [];

        // ---- Step 1: EXPIRE first ----
        // Campaigns in 'active' or 'scheduled' whose ends_at has passed.
        foreach ($repo->getCampaignsToExpire() as $campaign) {
            $expiredProductIds = array_merge(
                $expiredProductIds,
                $this->resolveProductIdsForCache($campaign->id)
            );
            $repo->updateStatus($campaign->id, $expireStatus);
        }

        // ---- Step 2: ACTIVATE next ----
        // Campaigns in 'scheduled' whose starts_at has arrived (and not yet expired).
        foreach ($repo->getCampaignsToActivate() as $campaign) {
            $activatedProductIds = array_merge(
                $activatedProductIds,
                $this->resolveProductIdsForCache($campaign->id)
            );
            $repo->updateStatus($campaign->id, 'active');
        }

        // ---- Step 3: Flush WooCommerce price cache for all affected products ----
        $allAffectedIds = array_unique(array_merge($expiredProductIds, $activatedProductIds));

        foreach ($allAffectedIds as $productId) {
            wc_delete_product_transients((int) $productId);
        }

        // Flush shop loop page cache if any product was affected.
        if (!empty($allAffectedIds)) {
            wc_delete_shop_order_transients();
        }
    }

    // -------------------------------------------------------
    // 4. FRONTEND BADGE
    // -------------------------------------------------------

    /**
     * Register hooks to render the classic discount badge on product cards.
     * Only active on the frontend; skipped inside wp-admin.
     */
    private function registerFrontendBadge(): void
    {
        if (is_admin()) {
            return;
        }

        Hooks::action('woocommerce_before_shop_loop_item_title', [$this, 'renderBadge'], 5);
        Hooks::action('woocommerce_single_product_summary',      [$this, 'renderBadge'], 5);
    }

    /**
     * Render the discount badge for a product in shop/product loops.
     *
     * Badge appearance (colors) is controlled by
     * Appearance → "بج تخفیف کلاسیک" settings.
     */
    public function renderBadge(): void
    {
        global $product;

        if (!($product instanceof \WC_Product)) {
            return;
        }

        $settings = $this->container
            ->make(\Msi\Campaignchi\Templates\Services\SliderSettingsService::class)
            ->getGlobalSettings();

        // Badge must be explicitly enabled in the Appearance settings.
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

        // Only render when the campaign actually reduces the price.
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
    // PRIVATE HELPERS
    // -------------------------------------------------------

    /**
     * Get the CampaignResolver instance from the DI container.
     *
     * @return CampaignResolver
     */
    private function resolver(): CampaignResolver
    {
        return $this->container->make(CampaignResolver::class);
    }

    /**
     * Get the CampaignRepository instance from the DI container.
     *
     * @return CampaignRepository
     */
    private function repo(): CampaignRepository
    {
        return $this->container->make(CampaignRepository::class);
    }

    /**
     * Resolve the affected product IDs for a campaign to flush WC transients.
     *
     * Returns an empty array for "all products" campaigns — flushing every
     * product transient would be too expensive on large stores. The broad
     * wc_delete_shop_order_transients() call in processAutoTransitions()
     * handles shop-level cache busting in that case.
     *
     * @param int $campaignId Campaign ID.
     * @return int[] Product IDs, or empty array if campaign affects all products.
     */
    private function resolveProductIdsForCache(int $campaignId): array
    {
        $campaign = $this->repo()->find($campaignId);

        if ($campaign === null) {
            return [];
        }

        $resolver   = $this->container->make(CampaignProductResolver::class);
        $productIds = $resolver->resolve($campaign);

        // The ALL_PRODUCTS sentinel means "every product" — too many to flush individually.
        if ($productIds === [CampaignProductResolver::ALL_PRODUCTS]) {
            return [];
        }

        return array_map('intval', $productIds);
    }
}