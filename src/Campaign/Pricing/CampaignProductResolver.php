<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Pricing;

use Msi\Campaignchi\Campaign\Models\Campaign;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;

if (!defined('ABSPATH')) {
    exit;
}

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
     * Resolve product IDs that match the campaign's attribute rules.
     *
     * BUG FIX: WooCommerce assigns attribute terms to both the parent
     * product AND its variations. However, `get_objects_in_term()` may
     * return variation post IDs instead of (or in addition to) the parent
     * product ID. Because `PricingServiceProvider::filterPrice()` always
     * looks up the PARENT product ID in the pricing map, any variation ID
     * that leaked into the map would never match — the campaign would
     * appear not to apply even though the attribute was correctly configured.
     *
     * Fix: after collecting all object IDs from the taxonomy terms, resolve
     * each one to its parent post ID when the post type is
     * `product_variation`. This guarantees the map always contains the
     * parent product ID regardless of whether WC stored the term on the
     * parent or the variation.
     *
     * @return int[]
     */
    private function productsByAttributeRules(int $campaignId): array
    {
        $rules     = $this->repo->getRules($campaignId);
        $attrRules = $rules['attribute_rules'] ?? [];

        if (empty($attrRules)) {
            return [];
        }

        // Group term IDs by taxonomy so we run one query per taxonomy.
        $byTaxonomy = [];
        foreach ($attrRules as $rule) {
            $taxonomy = $rule['taxonomy'] ?? '';
            $termId   = absint($rule['term_id'] ?? 0);

            if (!$taxonomy || !$termId || !taxonomy_exists($taxonomy)) {
                continue;
            }

            $byTaxonomy[$taxonomy][] = $termId;
        }

        $rawIds = [];
        foreach ($byTaxonomy as $taxonomy => $termIds) {
            $rawIds = array_merge($rawIds, $this->productsInTerms($termIds, $taxonomy));
        }

        // Resolve variation IDs → parent product IDs so the pricing map
        // always uses the same ID that filterPrice() looks up.
        $productIds = $this->normalizeToParentProductIds(array_unique($rawIds));

        return array_values(array_unique($productIds));
    }

    /**
     * Convert any `product_variation` post IDs to their parent product IDs.
     *
     * WooCommerce attribute terms can be stored on both `product` and
     * `product_variation` posts. The pricing engine always works with the
     * parent `product` ID (see `PricingServiceProvider::filterPrice()`),
     * so we must normalise here to avoid misses in the pricing map.
     *
     * @param int[] $postIds Mixed list of product and/or variation post IDs.
     * @return int[] List containing only parent product IDs (deduplicated).
     */
    private function normalizeToParentProductIds(array $postIds): array
    {
        if (empty($postIds)) {
            return [];
        }

        $parentIds = [];

        foreach ($postIds as $postId) {
            $postId = (int) $postId;

            if ($postId <= 0) {
                continue;
            }

            // If this is a variation, get_post_parent() returns the parent product ID.
            // For a normal product, get_post_type() returns 'product' and we use it as-is.
            if (get_post_type($postId) === 'product_variation') {
                $parentId = (int) wp_get_post_parent_id($postId);

                // Guard: if for some reason the parent lookup fails, fall
                // back to the original ID rather than silently dropping it.
                $parentIds[] = $parentId > 0 ? $parentId : $postId;
            } else {
                $parentIds[] = $postId;
            }
        }

        return $parentIds;
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