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
 * FIX — Section 10: processAutoTransitions
 *
 *  1. Cron schedule registered in register() (not boot()) so it exists
 *     at activation time — see FIX Issue A from previous session.
 *
 *  2. processAutoTransitions() now reads SettingsPage::getCampaign()
 *     ['auto_expire_status'] to decide the target status after expiry
 *     ('ended' or 'draft'). Previously it was hardcoded to 'ended',
 *     which ignored the admin's setting entirely.
 *
 *  3. After transitions, WooCommerce product price transients are flushed
 *     for all affected products so frontend prices update immediately.
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

        // FIX (Issue A): Register the custom cron interval HERE in register(),
        // not in boot(), so it exists before activation hooks fire.
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

        if ($final >= $regular) {
            return $price;
        }

        return (string) $final;
    }

    /**
     * Force "on sale" state so WooCommerce renders strike-through prices.
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
        Hooks::action('cmc_campaign_changed', [CampaignResolver::class, 'flushCache']);
        Hooks::action('set_object_terms', [CampaignResolver::class, 'flushCache']);
        Hooks::action('save_post_product', [CampaignResolver::class, 'flushCache']);
    }

    // -------------------------------------------------------
    // 3. CRON
    // -------------------------------------------------------

    private function registerCron(): void
    {
        if (!wp_next_scheduled('cmc_process_campaigns')) {
            wp_schedule_event(time(), 'cmc_five_minutes', 'cmc_process_campaigns');
        }

        Hooks::action('cmc_process_campaigns', [$this, 'processAutoTransitions']);
    }

    /**
     * Register the custom "every 5 minutes" cron interval.
     * Registered in register() — see constructor-level note above.
     */
    public function registerCronSchedule(array $schedules): array
    {
        if (!isset($schedules['cmc_five_minutes'])) {
            $schedules['cmc_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('هر ۵ دقیقه (کمپین‌چی)', 'campaignchi'),
            ];
        }

        return $schedules;
    }

    /**
     * Transition campaigns between scheduled → active → (ended | draft).
     *
     * Activation:
     *   Any campaign with status='scheduled' whose starts_at has arrived
     *   is flipped to 'active'. This works for both flash_sale and any
     *   other type (even though only flash_sale should reach 'scheduled').
     *
     * Expiry:
     *   Any active/scheduled campaign whose ends_at has passed is flipped
     *   to the status defined in Settings → Campaign Engine → "وضعیت پس
     *   از انقضا" (auto_expire_status: 'ended' | 'draft').
     *   Default: 'ended'.
     *
     * After each set of transitions:
     *   WooCommerce product price transients are flushed for all affected
     *   products so shop/single-product pages reflect new prices immediately.
     */
    public function processAutoTransitions(): void
    {
        $repo = $this->repo();

        // Read the admin-configured expiry target status.
        $campaignSettings = SettingsPage::getCampaign();
        $expireStatus     = in_array($campaignSettings['auto_expire_status'] ?? '', ['ended', 'draft'], true)
            ? $campaignSettings['auto_expire_status']
            : 'ended';

        $activatedProductIds = [];
        $expiredProductIds   = [];

        // scheduled → active
        foreach ($repo->getCampaignsToActivate() as $campaign) {
            $activatedProductIds = array_merge(
                $activatedProductIds,
                $this->resolveProductIdsForCache($campaign->id)
            );
            $repo->updateStatus($campaign->id, 'active');
        }

        // active/scheduled → ended|draft  (respects auto_expire_status setting)
        foreach ($repo->getCampaignsToExpire() as $campaign) {
            $expiredProductIds = array_merge(
                $expiredProductIds,
                $this->resolveProductIdsForCache($campaign->id)
            );
            $repo->updateStatus($campaign->id, $expireStatus);
        }

        // Flush WooCommerce price cache for all affected products.
        $allAffectedIds = array_unique(array_merge($activatedProductIds, $expiredProductIds));

        foreach ($allAffectedIds as $productId) {
            wc_delete_product_transients((int) $productId);
        }

        if (!empty($allAffectedIds)) {
            // Broad WC cache bust for shop loop pages.
            wc_delete_shop_order_transients();
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
     * Render the classic discount badge on product cards.
     * Colors come from Appearance → "بج تخفیف کلاسیک".
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
     * Resolve affected product IDs for a campaign to flush WC cache.
     * Returns empty array for "all products" campaigns (too expensive).
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

        if ($productIds === [CampaignProductResolver::ALL_PRODUCTS]) {
            return [];
        }

        return array_map('intval', $productIds);
    }
}