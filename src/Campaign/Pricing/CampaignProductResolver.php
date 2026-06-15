<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Pricing;

use Msi\Campaignchi\Campaign\Models\Campaign;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;

/**
 * CampaignProductResolver
 *
 * Given a campaign, resolves WHICH WooCommerce product IDs
 * it should apply to — based on its selection_mode:
 *   manual | category | tag | attribute | brand | all
 *
 * Special return value: ['*'] means "applies to all products"
 * (used by CampaignResolver as a fallback layer).
 *
 * @package Msi\Campaignchi\Campaign\Pricing
 */
class CampaignProductResolver
{
    /** Sentinel for "all products" */
    public const ALL_PRODUCTS = '*';

    public function __construct(private CampaignRepository $repo) {}

    /**
     * Resolve the list of product IDs targeted by a campaign.
     *
     * @return int[]|array{0: '*'}
     */
    public function resolve(Campaign $campaign): array
    {
        return match ($campaign->selectionMode) {
            'manual'    => $this->repo->getProductIds($campaign->id),
            'all'       => [self::ALL_PRODUCTS],
            'category'  => $this->productsByRuleTerms($campaign->id, 'category_ids', 'product_cat'),
            'tag'       => $this->productsByRuleTerms($campaign->id, 'tag_ids', 'product_tag'),
            'brand'     => $this->productsByRuleTerms($campaign->id, 'brand_ids', $this->detectBrandTaxonomy()),
            'attribute' => $this->productsByAttributeRules($campaign->id),
            default     => [],
        };
    }

    // -------------------------------------------------------
    // Category / Tag / Brand — single taxonomy, list of term IDs
    // -------------------------------------------------------

    /**
     * @return int[]
     */
    private function productsByRuleTerms(int $campaignId, string $ruleKey, ?string $taxonomy): array
    {
        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return [];
        }

        $rules   = $this->repo->getRules($campaignId);
        $termIds = array_map('absint', $rules[$ruleKey] ?? []);
        $termIds = array_filter($termIds);

        if (empty($termIds)) {
            return [];
        }

        return $this->productsInTerms($termIds, $taxonomy);
    }

    // -------------------------------------------------------
    // Attribute — multiple taxonomies, each with its own term IDs
    // -------------------------------------------------------

    /**
     * @return int[]
     */
    private function productsByAttributeRules(int $campaignId): array
    {
        $rules     = $this->repo->getRules($campaignId);
        $attrRules = $rules['attribute_rules'] ?? [];

        if (empty($attrRules)) {
            return [];
        }

        // Group term IDs by taxonomy so we run one query per taxonomy
        $byTaxonomy = [];
        foreach ($attrRules as $rule) {
            $taxonomy = $rule['taxonomy'] ?? '';
            $termId   = absint($rule['term_id'] ?? 0);

            if (!$taxonomy || !$termId || !taxonomy_exists($taxonomy)) {
                continue;
            }

            $byTaxonomy[$taxonomy][] = $termId;
        }

        $productIds = [];
        foreach ($byTaxonomy as $taxonomy => $termIds) {
            $productIds = array_merge($productIds, $this->productsInTerms($termIds, $taxonomy));
        }

        return array_values(array_unique($productIds));
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /**
     * @param int[]  $termIds
     * @return int[]
     */
    private function productsInTerms(array $termIds, string $taxonomy): array
    {
        $ids = get_objects_in_term($termIds, $taxonomy);

        if (is_wp_error($ids)) {
            return [];
        }

        return array_map('intval', $ids);
    }

    /**
     * Detect the active brand taxonomy on this site.
     * Tries WooCommerce Brands, YITH, BeRocket and PWB in order.
     */
    private function detectBrandTaxonomy(): ?string
    {
        foreach (['product_brand', 'yith_product_brand', 'berocket_brand', 'pwb-brand'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                return $taxonomy;
            }
        }

        return null;
    }
}