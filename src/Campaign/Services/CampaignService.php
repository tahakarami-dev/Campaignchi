<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Services;

use Msi\Campaignchi\Campaign\DTOs\CreateCampaignDTO;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;

/**
 * Campaign Service
 *
 * Business logic layer.
 * Controllers call this — never touch the repository directly.
 *
 * -----------------------------------------------------------------------
 * FIX — Section 10: Smart status derivation
 *
 *  Rule 1 — amazing_offer CANNOT be scheduled:
 *    amazing_offer campaigns have no starts_at / ends_at concept, so
 *    status='scheduled' is meaningless for them. If the user somehow
 *    submits status='scheduled' for an amazing_offer, this service
 *    silently overrides it to 'active'.
 *
 *  Rule 2 — Auto-set status='scheduled' when starts_at is in the future:
 *    If the user sets status='active' but supplies a starts_at that has
 *    not yet arrived, this service automatically changes status to
 *    'scheduled'. This ensures:
 *      - The pricing engine does NOT apply discounts yet (getLiveCampaigns
 *        only returns status='active' rows).
 *      - The cron will flip it to 'active' when starts_at arrives.
 *      - The admin UI shows the correct "زمان‌بندی شده" badge.
 *
 *  Rule 3 — Preserve 'draft':
 *    If the user explicitly saves as 'draft', no override happens.
 * -----------------------------------------------------------------------
 *
 * @package Msi\Campaignchi\Campaign\Services
 */
class CampaignService
{
    public function __construct(
        private CampaignRepository $repo
    ) {}

    // -------------------------------------------------------
    // CREATE
    // -------------------------------------------------------

    /**
     * Create a new campaign from raw POST data.
     *
     * @param array $post Raw $_POST
     * @return array{ success: bool, id?: int, message: string }
     */
    public function createFromPost(array $post): array
    {
        try {
            $dto = CreateCampaignDTO::fromPost($post);
            $dto = $this->applyStatusRules($dto);
            $id  = $this->repo->create($dto);

            return [
                'success' => true,
                'id'      => $id,
                'message' => __('کمپین با موفقیت ایجاد شد.', 'campaignchi'),
            ];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => __('خطا در ذخیره کمپین.', 'campaignchi')];
        }
    }

    // -------------------------------------------------------
    // UPDATE
    // -------------------------------------------------------

    /**
     * Update an existing campaign from raw POST data.
     *
     * @param int   $id
     * @param array $post
     * @return array{ success: bool, message: string }
     */
    public function updateFromPost(int $id, array $post): array
    {
        try {
            $campaign = $this->repo->find($id);
            if (!$campaign) {
                return ['success' => false, 'message' => __('کمپین یافت نشد.', 'campaignchi')];
            }

            $dto = CreateCampaignDTO::fromPost($post);
            $dto = $this->applyStatusRules($dto);
            $this->repo->update($id, $dto);

            return ['success' => true, 'message' => __('کمپین به‌روز شد.', 'campaignchi')];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => __('خطا در به‌روزرسانی.', 'campaignchi')];
        }
    }

    // -------------------------------------------------------
    // DELETE
    // -------------------------------------------------------

    /**
     * @return array{ success: bool, message: string }
     */
    public function delete(int $id): array
    {
        $campaign = $this->repo->find($id);
        if (!$campaign) {
            return ['success' => false, 'message' => __('کمپین یافت نشد.', 'campaignchi')];
        }

        $this->repo->delete($id);

        return ['success' => true, 'message' => __('کمپین حذف شد.', 'campaignchi')];
    }

    // -------------------------------------------------------
    // PRODUCT SEARCH
    // -------------------------------------------------------

    /**
     * @return array{ products: array, has_more: bool }
     */
    public function searchProducts(string $search = '', int $page = 1): array
    {
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $perPage + 1,
            'offset'         => $offset,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ];

        if (!empty($search)) {
            $args['s'] = sanitize_text_field($search);
        }

        $query   = new \WP_Query($args);
        $ids     = $query->posts;
        $hasMore = count($ids) > $perPage;

        if ($hasMore) {
            array_pop($ids);
        }

        $products = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }

            $products[] = [
                'id'        => $id,
                'name'      => $product->get_name(),
                'price'     => wc_price($product->get_price()),
                'price_raw' => (float) $product->get_price(),
                'sku'       => $product->get_sku(),
                'thumb'     => get_the_post_thumbnail_url($id, 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                'type'      => $product->get_type(),
            ];
        }

        return ['products' => $products, 'has_more' => $hasMore, 'page' => $page];
    }

    // -------------------------------------------------------
    // TAXONOMY TERMS
    // -------------------------------------------------------

    public function getCategories(): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'count' => $t->count, 'slug' => $t->slug], $terms);
    }

    public function getTags(): array
    {
        $terms = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'count' => $t->count], $terms);
    }

    public function getAttributes(): array
    {
        $attributes = wc_get_attribute_taxonomies();
        $result     = [];

        foreach ($attributes as $attr) {
            $taxonomy = wc_attribute_taxonomy_name($attr->attribute_name);
            $terms    = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            $result[] = [
                'taxonomy' => $taxonomy,
                'label'    => $attr->attribute_label,
                'terms'    => array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name], $terms),
            ];
        }

        return $result;
    }

    public function getBrands(): array
    {
        foreach (['product_brand', 'yith_product_brand', 'berocket_brand', 'pwb-brand'] as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            return array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'count' => $t->count, 'taxonomy' => $taxonomy], $terms);
        }

        return [];
    }

    // -------------------------------------------------------
    // ACCESSOR
    // -------------------------------------------------------

    public function getRepository(): CampaignRepository
    {
        return $this->repo;
    }

    // -------------------------------------------------------
    // STATUS RULES (business logic)
    // -------------------------------------------------------

    /**
     * Apply smart status derivation rules BEFORE persisting a campaign.
     *
     * Rules (applied in order, first match wins):
     *
     *  Rule 1 — amazing_offer + status='scheduled'
     *     → override to 'active'
     *     Reason: amazing_offer has no date concept; scheduling is meaningless.
     *
     *  Rule 2 — any type + ends_at already in the past + status NOT 'draft'
     *     → override to 'ended'
     *     Reason: admin set an end date that has already passed. Saving as
     *     'active' would briefly apply discounts until the next cron cycle.
     *     'draft' is exempt so admins can keep a record without re-activating.
     *
     *  Rule 3 — flash_sale + status='active' + starts_at is in the future
     *     → override to 'scheduled'
     *     Reason: the campaign should not be live until its start time.
     *     The cron (processAutoTransitions) will flip it to 'active' when
     *     starts_at arrives.
     *
     *  Rule 4 — status='draft'
     *     → never changed (admin explicitly chose not to publish yet).
     *
     * This method returns a new DTO with the corrected status because DTO
     * properties are readonly.
     */
    private function applyStatusRules(CreateCampaignDTO $dto): CreateCampaignDTO
    {
        $status = $dto->status;

        // Rule 1: amazing_offer cannot be scheduled.
        if ($dto->type === 'amazing_offer' && $status === 'scheduled') {
            $status = 'active';
        }

        // Rule 2: if ends_at has already passed, the campaign is over.
        // Skip this check for 'draft' (admin may want to keep it for reference).
        if (
            $status !== 'draft'
            && $dto->endsAt !== null
            && strtotime($dto->endsAt) < current_time('timestamp')
        ) {
            $status = 'ended';
        }

        // Rule 3: flash_sale with a future starts_at must wait as 'scheduled'.
        // Only applies when the admin tried to publish as 'active' and Rule 2
        // did not already flip the status to 'ended'.
        if (
            $status === 'active'
            && $dto->type === 'flash_sale'
            && $dto->startsAt !== null
            && strtotime($dto->startsAt) > current_time('timestamp')
        ) {
            $status = 'scheduled';
        }

        // Nothing changed — return original DTO to avoid an unnecessary object copy.
        if ($status === $dto->status) {
            return $dto;
        }

        // Return a new DTO with the corrected status; all other fields are preserved.
        return new CreateCampaignDTO(
            title:          $dto->title,
            type:           $dto->type,
            discount:       $dto->discount,
            discountType:   $dto->discountType,
            startsAt:       $dto->startsAt,
            endsAt:         $dto->endsAt,
            description:    $dto->description,
            selectionMode:  $dto->selectionMode,
            productIds:     $dto->productIds,
            categoryIds:    $dto->categoryIds,
            tagIds:         $dto->tagIds,
            attributeRules: $dto->attributeRules,
            brandIds:       $dto->brandIds,
            status:         $status,
        );
    }
}