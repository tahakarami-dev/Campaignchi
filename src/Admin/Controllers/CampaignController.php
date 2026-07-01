<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Controllers;

use Msi\Campaignchi\Campaign\Services\CampaignService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign AJAX Controller
 *
 * Handles all wp_ajax_cmc_* actions for the campaign module.
 * Each method: verify nonce → call service → return JSON.
 *
 * Registered actions:
 *   cmc_create_campaign
 *   cmc_update_campaign
 *   cmc_delete_campaign
 *   cmc_search_products
 *   cmc_get_picker_data     (categories / tags / attributes / brands)
 *   cmc_get_campaign        (single campaign for edit form)
 *
 * @package Msi\Campaignchi\Admin\Controllers
 */
class CampaignController
{
    public function __construct(
        private CampaignService $service
    ) {}

    // -------------------------------------------------------
    // REGISTER
    // -------------------------------------------------------

    /**
     * Register all AJAX hooks.
     * Called from AdminServiceProvider::boot().
     */
    public function register(): void
    {
        $actions = [
            'cmc_create_campaign' => 'create',
            'cmc_update_campaign' => 'update',
            'cmc_delete_campaign' => 'delete',
            'cmc_search_products' => 'searchProducts',
            'cmc_get_picker_data' => 'getPickerData',
            'cmc_get_campaign'    => 'getCampaign',
        ];

        foreach ($actions as $action => $method) {
            add_action("wp_ajax_{$action}", [$this, $method]);
        }
    }

    // -------------------------------------------------------
    // ACTIONS
    // -------------------------------------------------------

    /** POST: create a new campaign */
    public function create(): void
    {
        $this->verifyNonce();
        $result = $this->service->createFromPost($_POST);
        $this->json($result, $result['success'] ? 200 : 422);
    }

    /** POST: update an existing campaign */
    public function update(): void
    {
        $this->verifyNonce();
        $id     = absint($_POST['id'] ?? 0);
        $result = $this->service->updateFromPost($id, $_POST);
        $this->json($result, $result['success'] ? 200 : 422);
    }

    /** POST: delete a campaign */
    public function delete(): void
    {
        $this->verifyNonce();
        $id     = absint($_POST['id'] ?? 0);
        $result = $this->service->delete($id);
        $this->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * GET: search products (lightweight, paginated)
     * Params: search (string), page (int)
     */
    public function searchProducts(): void
    {
        $this->verifyNonce();

        $search = sanitize_text_field($_GET['search'] ?? '');
        $page   = max(1, absint($_GET['page'] ?? 1));

        $result = $this->service->searchProducts($search, $page);
        $this->json(['success' => true, 'data' => $result]);
    }

    /**
     * GET: load all picker data in one shot
     * (categories + tags + attributes + brands)
     * Called once when the form loads — cached in JS.
     */
    public function getPickerData(): void
    {
        $this->verifyNonce();

        $this->json([
            'success' => true,
            'data'    => [
                'categories' => $this->service->getCategories(),
                'tags'       => $this->service->getTags(),
                'attributes' => $this->service->getAttributes(),
                'brands'     => $this->service->getBrands(),
            ],
        ]);
    }

    /**
     * GET: get a single campaign with its products and rules for editing.
     * Param: id (int)
     */
    public function getCampaign(): void
    {
        $this->verifyNonce();

        $id       = absint($_GET['id'] ?? 0);
        $campaign = $this->service->getRepository()->find($id);

        if (!$campaign) {
            $this->json(['success' => false, 'message' => __('کمپین یافت نشد.', 'campaignchi')], 404);
        }

        $productIds = $this->service->getRepository()->getProductIds($id);
        $rules      = $this->service->getRepository()->getRules($id);

        // Load minimal product data for selected products
        $products = [];
        foreach ($productIds as $pid) {
            $p = wc_get_product($pid);
            if (!$p) {
                continue;
            }
            $products[] = [
                'id'    => $pid,
                'name'  => $p->get_name(),
                'price' => wc_price($p->get_price()),
                'thumb' => get_the_post_thumbnail_url($pid, 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
            ];
        }

        $this->json([
            'success'  => true,
            'campaign' => [
                'id'             => $campaign->id,
                'title'          => $campaign->title,
                'type'           => $campaign->type,
                'status'         => $campaign->status,
                'discount'       => $campaign->discount,
                'discount_type'  => $campaign->discountType,
                // Needed so the edit form activates the correct tab and
                // restores the previously selected products/rules.
                'selection_mode' => $campaign->selectionMode,
                'starts_at'      => $campaign->startsAt,
                'ends_at'        => $campaign->endsAt,
                'description'    => $campaign->description,
            ],
            'products' => $products,
            'rules'    => $rules,
        ]);
    }

    // -------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------

    /**
     * Verify WP nonce — die on failure.
     */
    private function verifyNonce(): void
    {
        if (!check_ajax_referer('cmc_admin', 'nonce', false)) {
            $this->json(['success' => false, 'message' => __('درخواست نامعتبر.', 'campaignchi')], 403);
        }

        if (!current_user_can('manage_options')) {
            $this->json(['success' => false, 'message' => __('دسترسی مجاز نیست.', 'campaignchi')], 403);
        }
    }

    /**
     * Send JSON response and exit.
     *
     * @param array $data
     * @param int   $status HTTP status code
     */
    private function json(array $data, int $status = 200): never
    {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($data);
        exit;
    }
}