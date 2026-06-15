<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Pricing;

use Msi\Campaignchi\Campaign\Models\Campaign;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;

/**
 * CampaignResolver
 *
 * The runtime "brain" of the pricing engine.
 *
 * Builds a cached map of:  product_id => campaign info
 * by resolving every currently-LIVE campaign's target products.
 *
 * CACHE STRATEGY (important):
 * The cache is NOT a fixed TTL. Each time it's rebuilt, we ask
 * CampaignRepository::getNextTransitionTimestamp() for the soonest
 * moment a flash-sale campaign is due to start or end, and set the
 * transient to expire EXACTLY then (bounded between MIN/MAX). This
 * guarantees discounts turn on/off on schedule, down to the minute,
 * instead of lagging behind a fixed 5-minute window.
 *
 * Also invalidated immediately whenever a campaign or product taxonomy
 * changes (see PricingServiceProvider).
 *
 * @package Msi\Campaignchi\Campaign\Pricing
 */
class CampaignResolver
{
    private const CACHE_KEY = 'cmc_pricing_map_v1';

    /** Never cache for less than this (avoid hammering the DB) */
    private const MIN_CACHE_TTL = 20; // seconds

    /** Never cache for longer than this (fallback when nothing is scheduled) */
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
     * @param int $productId Real product ID (parent ID for variations)
     * @return array{
     *     id: int,
     *     title: string,
     *     type: string,
     *     discount: float,
     *     discount_type: string,
     *     ends_at: ?string
     * }|null
     */
    public function findForProduct(int $productId): ?array
    {
        $this->loadMap();

        if (isset($this->map[$productId])) {
            return $this->map[$productId];
        }

        // Fallback: "all products" campaigns (already priority-sorted)
        foreach ($this->fallbackCampaigns as $campaign) {
            return $campaign;
        }

        return null;
    }

    /**
     * Flush the cached pricing map.
     * Call this whenever a campaign or product taxonomy changes.
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
     * We look up the soonest moment a flash-sale campaign is due to
     * start or end, and cap the cache to expire exactly then — so
     * discounts switch on/off on schedule (within MIN_CACHE_TTL
     * seconds of accuracy), not whenever a fixed TTL happens to run out.
     */
    private function calculateCacheTtl(): int
    {
        $next = $this->repo->getNextTransitionTimestamp();

        if ($next === null) {
            return self::MAX_CACHE_TTL;
        }

        $diff = $next - time();

        return max(self::MIN_CACHE_TTL, min($diff, self::MAX_CACHE_TTL));
    }

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