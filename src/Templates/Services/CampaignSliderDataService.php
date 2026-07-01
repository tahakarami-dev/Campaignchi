<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Services;

use Msi\Campaignchi\Campaign\Models\Campaign;
use Msi\Campaignchi\Campaign\Pricing\CampaignProductResolver;
use Msi\Campaignchi\Campaign\Pricing\PriceCalculator;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign Slider Data Service
 *
 * Resolves everything a slider needs to render, from just a campaign id
 * (or 0/null for "auto-pick") plus a product limit/order:
 *
 *   - which Campaign to use (must currently be LIVE — an ended or
 *     not-yet-started campaign is never shown, since its discounted
 *     prices would no longer be real)
 *   - the resolved list of products, each with WooCommerce's own
 *     (already campaign-discounted) price HTML, percent-off, and a
 *     lightweight stock-urgency indicator
 *   - a timezone-correct countdown target, only when the campaign is a
 *     flash sale with an end date
 *
 * This is the ONLY place that talks to WooCommerce/wpdb for slider data —
 * the shortcode, the Elementor widget, and the admin live-preview AJAX
 * handler all call this same service, so they can never drift apart.
 *
 * @package Msi\Campaignchi\Templates\Services
 */
class CampaignSliderDataService
{
    public function __construct(
        private CampaignRepository $campaigns,
        private CampaignProductResolver $productResolver
    ) {}

    /**
     * @param int|null $campaignId Explicit campaign id, or null/0 for "auto-pick the highest-priority live campaign".
     * @param int      $limit      Max number of products to return.
     * @param string   $order      'priority' | 'random' | 'newest'
     * @return array{campaign: Campaign, products: array, countdown_iso: ?string}|null Null when there is nothing to show.
     */
    public function resolve(?int $campaignId, int $limit, string $order): ?array
    {
        $campaign = $this->resolveLiveCampaign($campaignId);

        if ($campaign === null) {
            return null;
        }

        $productIds = $this->productResolver->resolve($campaign);
        $isAllProducts = $productIds === [CampaignProductResolver::ALL_PRODUCTS];

        $ids = $isAllProducts
            ? $this->fallbackProductIds($limit, $order)
            : $this->sortAndLimit($productIds, $limit, $order);

        $products = [];
        foreach ($ids as $productId) {
            $product = wc_get_product($productId);

            if (!$product || $product->get_status() !== 'publish') {
                continue;
            }

            $products[] = $this->mapProduct($product);
        }

        if (empty($products)) {
            return null;
        }

        return [
            'campaign'      => $campaign,
            'products'      => $products,
            'countdown_iso' => $this->countdownIso($campaign),
        ];
    }

    /**
     * Find the requested campaign among the currently LIVE campaigns
     * (reuses CampaignRepository::getLiveCampaigns() so "live" means
     * exactly the same thing here as it does for the actual pricing
     * engine — no duplicated/diverging live-state logic).
     *
     * @param int|null $campaignId 0/null = auto-pick the first (highest priority) live campaign.
     */
    private function resolveLiveCampaign(?int $campaignId): ?Campaign
    {
        $live = $this->campaigns->getLiveCampaigns();

        if (empty($live)) {
            return null;
        }

        if (!$campaignId) {
            return $live[0];
        }

        foreach ($live as $campaign) {
            if ($campaign->id === $campaignId) {
                return $campaign;
            }
        }

        // An explicit campaign was requested but it is not currently live
        // (draft/ended/not-yet-started) — render nothing rather than show
        // stale or incorrect pricing.
        return null;
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    private function sortAndLimit(array $ids, int $limit, string $order): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($order === 'random') {
            shuffle($ids);
        } elseif ($order === 'newest') {
            // Heuristic: a higher WooCommerce post ID was created later.
            rsort($ids);
        }
        // 'priority' (default): keep the resolver's own natural order.

        return array_slice($ids, 0, max(1, $limit));
    }

    /** @return int[] */
    private function fallbackProductIds(int $limit, string $order): array
    {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => max(1, $limit),
            'fields'         => 'ids',
            'orderby'        => $order === 'random' ? 'rand' : 'date',
            'order'          => 'DESC',
        ];

        return (new \WP_Query($args))->posts;
    }

    /**
     * @return array{
     *   id:int, name:string, permalink:string, image:string, price_html:string,
     *   percent_off:int, stock: array{qty:int, percent:int}|null
     * }
     */
    private function mapProduct(\WC_Product $product): array
    {
        $regular = (float) $product->get_regular_price();
        $current = (float) $product->get_price(); // already campaign-discounted via PricingServiceProvider's price filters.

        return [
            'id'          => $product->get_id(),
            'name'        => $product->get_name(),
            'permalink'   => (string) get_permalink($product->get_id()),
            'image'       => (string) (get_the_post_thumbnail_url($product->get_id(), 'large') ?: wc_placeholder_img_src('large')),
            // get_price_html() also reflects the campaign discount (is_on_sale() is filtered too), so the
            // strike-through/sale display matches WooCommerce's own conventions exactly — no manual HTML building.
            'price_html'  => $product->get_price_html(),
            'percent_off' => PriceCalculator::percentOff($regular, $current),
            'stock'       => $this->stockUrgency($product),
        ];
    }

    /**
     * Lightweight stock-urgency indicator for a single product.
     *
     * WooCommerce never stores "how much stock this product started
     * with", so a true "% sold" bar isn't available. Instead we show
     * urgency relative to a configurable reference point: the product's
     * own low-stock threshold (or a sensible default), scaled up — i.e.
     * the bar fills up as the product approaches its low-stock warning
     * level. This is intentionally a simple heuristic, not a precise
     * "percent of original stock" calculation.
     *
     * @return array{qty:int, percent:int}|null Null when stock isn't being managed for this product.
     */
    private function stockUrgency(\WC_Product $product): ?array
    {
        if (!$product->managing_stock()) {
            return null;
        }

        $qty = (int) $product->get_stock_quantity();
        $lowStockAmount = (int) ($product->get_low_stock_amount() ?: 5);
        $reference = max($lowStockAmount * 3, 10);

        return [
            'qty'     => max(0, $qty),
            'percent' => (int) max(5, min(100, round(($qty / $reference) * 100))),
        ];
    }

    /**
     * Resolve a flash-sale campaign's end date into a true UTC instant,
     * ready for `new Date()` in JavaScript.
     *
     * `Campaign::$endsAt` is a NAIVE, site-local datetime string (entered
     * via the Jalali date picker, with no timezone information attached).
     * That's fine for admin-only displays (JalaliHelper always assumes
     * "site timezone"), but a public countdown is seen by visitors in any
     * timezone in the world — so we must explicitly attach the site's
     * timezone here and convert to UTC, producing an ISO 8601 string with
     * an explicit offset. Without this conversion, every visitor's
     * browser would (incorrectly) interpret the naive string as being in
     * THEIR OWN local time, making the countdown wrong everywhere except
     * for visitors who happen to share the site's timezone.
     */
    private function countdownIso(Campaign $campaign): ?string
    {
        if ($campaign->type !== 'flash_sale' || empty($campaign->endsAt)) {
            return null;
        }

        try {
            $siteLocal = new \DateTime($campaign->endsAt, wp_timezone());

            return $siteLocal->setTimezone(new \DateTimeZone('UTC'))->format('c');
        } catch (\Exception $e) {
            return null;
        }
    }
}
