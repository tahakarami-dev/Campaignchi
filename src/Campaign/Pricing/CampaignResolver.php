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
 * Cached for CACHE_TTL seconds via transient, and invalidated
 * immediately whenever a campaign or product taxonomy changes
 * (see PricingServiceProvider).
 *
 * @package Msi\Campaignchi\Campaign\Pricing
 */
class CampaignResolver
{
    private const CACHE_KEY = 'cmc_pricing_map_v1';
    private const CACHE_TTL = 300; // 5 minutes

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
        ], self::CACHE_TTL);
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