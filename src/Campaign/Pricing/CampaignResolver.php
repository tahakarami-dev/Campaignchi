<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Pricing;

use Msi\Campaignchi\Admin\Pages\SettingsPage;
use Msi\Campaignchi\Campaign\Models\Campaign;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CampaignResolver
 *
 * The runtime "brain" of the pricing engine.
 *
 * Builds a cached map of:  product_id => campaign info
 * by resolving every currently-LIVE campaign's target products.
 *
 * CACHE STRATEGY:
 * The cache TTL is not fixed. Each rebuild asks
 * CampaignRepository::getNextTransitionTimestamp() for the soonest
 * moment a flash-sale campaign is due to start or end, and sets the
 * transient to expire EXACTLY then (bounded between MIN/MAX). This
 * guarantees discounts turn on/off on schedule, down to the minute.
 *
 * -----------------------------------------------------------------------
 * FIX (Section 10 — Issue C — Timezone mismatch in calculateCacheTtl):
 *
 * getNextTransitionTimestamp() returns strtotime() applied to a naive,
 * site-local DATETIME string. PHP's strtotime() with no explicit timezone
 * uses the PHP default timezone (UTC on most hosts), so the returned
 * integer is effectively a "site-local Unix timestamp" — NOT a true UTC
 * timestamp. The diff must therefore be against current_time('timestamp')
 * (site-local "now" as a Unix integer), NOT against time() (true UTC now).
 *
 * On a server where the site timezone is UTC+3:30 (Asia/Tehran):
 *   time()                    → e.g. 1_700_000_000  (UTC)
 *   current_time('timestamp') → e.g. 1_700_012_600  (UTC + 3.5h offset)
 *   strtotime(site-local str) → e.g. 1_700_012_600  (same convention)
 *
 * Using time() in the diff would produce:
 *   diff = 1_700_012_600 - 1_700_000_000 = +12_600 s (always ≥ MAX_CACHE_TTL)
 *   → TTL always hits MAX_CACHE_TTL → cache never expires early → transitions lag
 *
 * Using current_time('timestamp') produces the correct diff.
 *
 * The previous code already used current_time('timestamp') but a comment
 * above it incorrectly stated "previously used time()". This version
 * keeps current_time('timestamp') and removes the misleading comment.
 * An extra guard is added to clamp the diff at MIN_CACHE_TTL to prevent
 * a zero or negative TTL when a transition is overdue.
 * -----------------------------------------------------------------------
 *
 * @package Msi\Campaignchi\Campaign\Pricing
 */
class CampaignResolver
{
    private const CACHE_KEY = 'cmc_pricing_map_v1';

    /** Never cache for less than this (avoids hammering the DB). */
    private const MIN_CACHE_TTL = 20; // seconds

    /** Never cache for longer than this (fallback when nothing is scheduled). */
    private const MAX_CACHE_TTL = 300; // 5 minutes

    /** @var array<int, array>|null product_id => campaign info */
    private ?array $map = null;

    /** @var array<int, array>|null "all products" campaigns, priority-ordered */
    private ?array $fallbackCampaigns = null;

    public function __construct(
        private CampaignRepository $repo,
        private CampaignProductResolver $productResolver
    ) {}

    // -------------------------------------------------------
    // PUBLIC API
    // -------------------------------------------------------

    /**
     * Find the campaign (if any) that should discount this product right now.
     *
     * @param int $productId Real product ID (parent ID for variations).
     * @return array{id:int,title:string,type:string,discount:float,discount_type:string,ends_at:?string}|null
     */
    public function findForProduct(int $productId): ?array
    {
        $this->loadMap();

        if (isset($this->map[$productId])) {
            return $this->map[$productId];
        }

        // Fallback: "all products" campaigns (already priority-sorted).
        foreach ($this->fallbackCampaigns as $campaign) {
            return $campaign;
        }

        return null;
    }

    /**
     * Flush the cached pricing map.
     * Called whenever a campaign or product taxonomy changes.
     */
    public static function flushCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    // -------------------------------------------------------
    // INTERNAL
    // -------------------------------------------------------

    private function loadMap(): void
    {
        if ($this->map !== null) {
            return;
        }

        $cached = get_transient(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['map'], $cached['fallback'])) {
            $this->map               = $cached['map'];
            $this->fallbackCampaigns = $cached['fallback'];
            return;
        }

        $this->build();

        set_transient(self::CACHE_KEY, [
            'map'      => $this->map,
            'fallback' => $this->fallbackCampaigns,
        ], $this->calculateCacheTtl());
    }

    /**
     * Build the product → campaign map from scratch.
     *
     * Campaigns are returned by the repository already ordered by priority
     * (flash_sale first, newest first) — so the FIRST campaign to claim a
     * product wins, and later campaigns are simply ignored for that product.
     */
    private function build(): void
    {
        $this->map               = [];
        $this->fallbackCampaigns = [];

        $campaigns = $this->repo->getLiveCampaigns();

        foreach ($campaigns as $campaign) {
            $info       = $this->toArray($campaign);
            $productIds = $this->productResolver->resolve($campaign);

            if ($productIds === [CampaignProductResolver::ALL_PRODUCTS]) {
                $this->fallbackCampaigns[] = $info;
                continue;
            }

            foreach ($productIds as $productId) {
                $productId = (int) $productId;

                if ($productId > 0 && !isset($this->map[$productId])) {
                    $this->map[$productId] = $info;
                }
            }
        }
    }

    /**
     * Decide how long the freshly-built pricing map may stay cached.
     *
     * We ask the repository for the next moment a campaign will start or
     * end, then expire the cache exactly then. This keeps discounts
     * switching on/off on schedule without a fixed TTL delay.
     *
     * Special cases from getNextTransitionTimestamp():
     *   - Returns null  → no pending transitions → use $maxTtl
     *   - Returns 0     → overdue scheduled campaign (cron is late)
     *                     → use MIN_CACHE_TTL so the map is rebuilt ASAP
     *                        after the cron runs and activates the campaign
     *   - Returns a future timestamp → compute diff and clamp between
     *                     MIN_CACHE_TTL and $maxTtl
     *
     * FIX (Issue C): getNextTransitionTimestamp() uses strtotime() on a
     * naive, site-local DATETIME string. The correct "now" to diff against
     * is current_time('timestamp') — the site-local Unix timestamp — NOT
     * time() (true UTC). On sites with a non-UTC timezone, using time()
     * would inflate the diff by the UTC offset, making TTL always equal
     * MAX_CACHE_TTL and breaking on-schedule transitions.
     *
     * Additional guard: clamp to MIN_CACHE_TTL even when $diff ≤ 0
     * (the transition is already overdue) to avoid setting a zero or
     * negative TTL on the transient.
     */
    private function calculateCacheTtl(): int
    {
        // Admin-configurable ceiling (Settings → Performance → pricing_cache_ttl),
        // falling back to the hardcoded MAX_CACHE_TTL when unavailable.
        $maxTtl = $this->maxCacheTtl();

        $next = $this->repo->getNextTransitionTimestamp();

        // No pending transitions at all.
        if ($next === null) {
            return $maxTtl;
        }

        // Overdue signal: a scheduled campaign's starts_at has passed but the cron
        // has not run yet. Use MIN_CACHE_TTL so we rebuild on the next request
        // after the cron activates the campaign.
        if ($next === 0) {
            return self::MIN_CACHE_TTL;
        }

        // Future transition: expire cache exactly when the transition is due.
        // current_time('timestamp') uses the same naive-site-local convention
        // as getNextTransitionTimestamp() — see the class docblock for details.
        $now  = current_time('timestamp');
        $diff = $next - $now;

        // Clamp: never below MIN (transition may already be overdue),
        // never above the admin-configured max (safety valve when nothing is upcoming).
        return max(self::MIN_CACHE_TTL, min($diff, $maxTtl));
    }

    /**
     * Resolve the configured maximum pricing-map cache TTL.
     *
     * Reads the admin setting and clamps it to a sane range so a bad value
     * can never starve or over-cache the pricing map. Falls back to the
     * MAX_CACHE_TTL constant if the settings layer is unavailable.
     */
    private function maxCacheTtl(): int
    {
        if (!class_exists(SettingsPage::class)) {
            return self::MAX_CACHE_TTL;
        }

        $configured = (int) (SettingsPage::getPerformance()['pricing_cache_ttl'] ?? self::MAX_CACHE_TTL);

        return max(self::MIN_CACHE_TTL, min($configured, 3600));
    }

    /** @return array{id:int,title:string,type:string,discount:float,discount_type:string,ends_at:?string} */
    private function toArray(Campaign $campaign): array
    {
        return [
            'id'            => $campaign->id,
            'title'         => $campaign->title,
            'type'          => $campaign->type,
            'discount'      => $campaign->discount,
            'discount_type' => $campaign->discountType,
            'ends_at'       => $campaign->endsAt,
        ];
    }
}