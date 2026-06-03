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
     * @param int $id
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
    // PRODUCT SEARCH (lightweight, paginated)
    // -------------------------------------------------------

    /**
     * Search WooCommerce products for the picker.
     * Returns minimal data — only what the UI needs.
     *
     * @param string $search  Search term
     * @param int    $page    Page number (20 per page)
     * @return array{ products: array, has_more: bool }
     */
    public function searchProducts(string $search = '', int $page = 1): array
    {
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $perPage + 1,  // fetch +1 to detect has_more
            'offset'         => $offset,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',          // only IDs — fastest query
        ];

        if (!empty($search)) {
            $args['s'] = sanitize_text_field($search);
        }

        $query    = new \WP_Query($args);
        $ids      = $query->posts;
        $hasMore  = count($ids) > $perPage;

        if ($hasMore) {
            array_pop($ids); // remove the extra item
        }

        // Build minimal product data — no heavy meta
        $products = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }

            $products[] = [
                'id'          => $id,
                'name'        => $product->get_name(),
                'price'       => wc_price($product->get_price()),
                'price_raw'   => (float) $product->get_price(),
                'sku'         => $product->get_sku(),
                'thumb'       => get_the_post_thumbnail_url($id, 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                'type'        => $product->get_type(),
            ];
        }

        return [
            'products' => $products,
            'has_more' => $hasMore,
            'page'     => $page,
        ];
    }

    // -------------------------------------------------------
    // TAXONOMY TERMS (for group selection)
    // -------------------------------------------------------

    /**
     * Get all product categories (flat list for picker).
     *
     * @return array
     */
    public function getCategories(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(fn($t) => [
            'id'    => $t->term_id,
            'name'  => $t->name,
            'count' => $t->count,
            'slug'  => $t->slug,
        ], $terms);
    }

    /**
     * Get all product tags.
     *
     * @return array
     */
    public function getTags(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product_tag',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(fn($t) => [
            'id'    => $t->term_id,
            'name'  => $t->name,
            'count' => $t->count,
        ], $terms);
    }

    /**
     * Get all WooCommerce product attributes and their terms.
     *
     * @return array
     */
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
                'terms'    => array_map(fn($t) => [
                    'id'   => $t->term_id,
                    'name' => $t->name,
                ], $terms),
            ];
        }

        return $result;
    }

    /**
     * Get all WooCommerce product brands.
     * Supports: product_brand (WooCommerce Brands), yith_product_brand, berocket_brand
     *
     * @return array
     */
    public function getBrands(): array
    {
        // Try common brand taxonomies
        $taxonomies = ['product_brand', 'yith_product_brand', 'berocket_brand', 'pwb-brand'];

        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            return array_map(fn($t) => [
                'id'       => $t->term_id,
                'name'     => $t->name,
                'count'    => $t->count,
                'taxonomy' => $taxonomy,
            ], $terms);
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
}
