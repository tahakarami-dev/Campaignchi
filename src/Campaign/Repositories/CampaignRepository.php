<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Repositories;

use Msi\Campaignchi\Campaign\Models\Campaign;
use Msi\Campaignchi\Campaign\DTOs\CreateCampaignDTO;

/**
 * Campaign Repository
 *
 * All database interaction for campaigns lives here.
 * Business logic stays in CampaignService.
 *
 * Tables:
 *   {prefix}cmc_campaigns
 *   {prefix}cmc_campaign_products
 *   {prefix}cmc_campaign_rules
 *
 * @package Msi\Campaignchi\Campaign\Repositories
 */
class CampaignRepository
{
    // -------------------------------------------------------
    // READ
    // -------------------------------------------------------

    /**
     * Get paginated list of campaigns.
     *
     * @param array $args {
     *   int    $per_page  Default 20
     *   int    $page      Default 1
     *   string $status    Filter by status (optional)
     *   string $search    Search in title (optional)
     *   string $orderby   Column name, default created_at
     *   string $order     ASC|DESC default DESC
     * }
     * @return array{ items: Campaign[], total: int }
     */
    public function paginate(array $args = []): array
    {
        global $wpdb;

        $perPage = max(1, (int) ($args['per_page'] ?? 20));
        $page    = max(1, (int) ($args['page']     ?? 1));
        $offset  = ($page - 1) * $perPage;
        $orderby = in_array($args['orderby'] ?? '', ['title', 'status', 'created_at', 'starts_at'], true)
            ? $args['orderby']
            : 'created_at';
        $order   = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $table  = $wpdb->prefix . 'cmc_campaigns';
        $where  = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = sanitize_key($args['status']);
        }

        if (!empty($args['search'])) {
            $where[]  = 'title LIKE %s';
            $params[] = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
        }

        $whereSQL = implode(' AND ', $where);

        // Total count
        $countSQL = "SELECT COUNT(*) FROM {$table} WHERE {$whereSQL}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var(
            empty($params) ? $countSQL : $wpdb->prepare($countSQL, ...$params)
        );

        // Rows
        $rowSQL = "SELECT * FROM {$table} WHERE {$whereSQL} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $allParams = array_merge($params, [$perPage, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($rowSQL, ...$allParams));

        return [
            'items' => array_map([Campaign::class, 'fromRow'], $rows ?: []),
            'total' => $total,
        ];
    }

    /**
     * Get a single campaign by ID.
     *
     * @param int $id
     * @return Campaign|null
     */
    public function find(int $id): ?Campaign
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id)
        );

        return $row ? Campaign::fromRow($row) : null;
    }

    // -------------------------------------------------------
    // WRITE
    // -------------------------------------------------------

    /**
     * Insert a new campaign from DTO.
     * Handles products + rules in a transaction-like manner.
     *
     * @param CreateCampaignDTO $dto
     * @return int New campaign ID
     * @throws \RuntimeException On DB failure
     */
    public function create(CreateCampaignDTO $dto): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';

        // ⚠️ FIX باگ ۱: ستون selection_mode به insert اضافه شد
        $wpdb->insert($table, [
            'title'          => $dto->title,
            'status'         => $dto->status,
            'type'           => $dto->type,
            'discount'       => $dto->discount,
            'discount_type'  => $dto->discountType,
            'selection_mode' => $dto->selectionMode,
            'starts_at'      => $dto->startsAt,
            'ends_at'        => $dto->endsAt,
            'description'    => $dto->description,
        ], ['%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s']);

        if (!$wpdb->insert_id) {
            throw new \RuntimeException('[CMC] Failed to insert campaign.');
        }

        $id = (int) $wpdb->insert_id;

        // Sync products and rules
        $this->syncProducts($id, $dto);
        $this->syncRules($id, $dto);

        return $id;
    }

    /**
     * Update existing campaign.
     *
     * @param int               $id
     * @param CreateCampaignDTO $dto
     * @throws \RuntimeException On DB failure
     */
    public function update(int $id, CreateCampaignDTO $dto): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';

        // ⚠️ FIX باگ ۱: ستون selection_mode به update اضافه شد
        $wpdb->update(
            $table,
            [
                'title'          => $dto->title,
                'status'         => $dto->status,
                'type'           => $dto->type,
                'discount'       => $dto->discount,
                'discount_type'  => $dto->discountType,
                'selection_mode' => $dto->selectionMode,
                'starts_at'      => $dto->startsAt,
                'ends_at'        => $dto->endsAt,
                'description'    => $dto->description,
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        // Re-sync products and rules
        $this->deleteProducts($id);
        $this->deleteRules($id);
        $this->syncProducts($id, $dto);
        $this->syncRules($id, $dto);
    }

    /**
     * Delete a campaign and all its related records.
     *
     * @param int $id
     */
    public function delete(int $id): void
    {
        global $wpdb;

        $this->deleteProducts($id);
        $this->deleteRules($id);

        $wpdb->delete(
            $wpdb->prefix . 'cmc_campaigns',
            ['id' => $id],
            ['%d']
        );
    }

    // -------------------------------------------------------
    // PRODUCT IDs for a campaign
    // -------------------------------------------------------

    /**
     * Get product IDs attached to a campaign.
     *
     * @param int $campaignId
     * @return int[]
     */
    public function getProductIds(int $campaignId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaign_products';
        $rows  = $wpdb->get_col(
            $wpdb->prepare("SELECT product_id FROM {$table} WHERE campaign_id = %d", $campaignId)
        );

        return array_map('intval', $rows ?: []);
    }

    /**
     * Get rules for a campaign (category/tag/attribute/brand selections),
     * grouped and shaped exactly as the edit form (campaigns.js) expects:
     *
     *   [
     *     'category_ids'    => int[],
     *     'tag_ids'         => int[],
     *     'brand_ids'       => int[],
     *     'attribute_rules' => [ ['taxonomy' => string, 'term_id' => int], ... ],
     *   ]
     *
     * ⚠️ FIX باگ ۲: قبلاً این متد آرایه‌ی خام ردیف‌های جدول رو برمی‌گرداند
     * که با ساختار مورد انتظار JS (rules.category_ids, rules.tag_ids, ...)
     * مطابقت نداشت و همیشه undefined می‌شد.
     *
     * @param int $campaignId
     * @return array
     */
    public function getRules(int $campaignId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaign_rules';

        $result = [
            'category_ids'    => [],
            'tag_ids'         => [],
            'brand_ids'       => [],
            'attribute_rules' => [],
        ];

        // اگر جدول وجود نداشته باشد (مثلاً نصب قدیمی)، خروجی خالی برگردان
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return $result;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE campaign_id = %d", $campaignId),
            ARRAY_A
        ) ?: [];

        foreach ($rows as $row) {
            switch ($row['rule_type']) {
                case 'category':
                    $result['category_ids'][] = (int) $row['term_id'];
                    break;

                case 'tag':
                    $result['tag_ids'][] = (int) $row['term_id'];
                    break;

                case 'brand':
                    $result['brand_ids'][] = (int) $row['term_id'];
                    break;

                case 'attribute':
                    $result['attribute_rules'][] = [
                        'taxonomy' => (string) $row['taxonomy'],
                        'term_id'  => (int) $row['term_id'],
                    ];
                    break;
            }
        }

        return $result;
    }

    // -------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------

    /** Insert product pivot rows */
    private function syncProducts(int $campaignId, CreateCampaignDTO $dto): void
    {
        global $wpdb;

        if (empty($dto->productIds)) {
            return;
        }

        $table = $wpdb->prefix . 'cmc_campaign_products';

        foreach (array_unique($dto->productIds) as $productId) {
            $wpdb->insert(
                $table,
                ['campaign_id' => $campaignId, 'product_id' => $productId],
                ['%d', '%d']
            );
        }
    }

    /** Insert rule rows for category/tag/attribute/brand selections */
    private function syncRules(int $campaignId, CreateCampaignDTO $dto): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaign_rules';

        // اگر جدول وجود ندارد، چیزی برای ذخیره نیست (نصب قدیمی)
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }

        $rows = [];

        foreach ($dto->categoryIds as $termId) {
            $rows[] = ['campaign_id' => $campaignId, 'rule_type' => 'category', 'taxonomy' => 'product_cat', 'term_id' => $termId];
        }

        foreach ($dto->tagIds as $termId) {
            $rows[] = ['campaign_id' => $campaignId, 'rule_type' => 'tag', 'taxonomy' => 'product_tag', 'term_id' => $termId];
        }

        foreach ($dto->attributeRules as $rule) {
            $rows[] = ['campaign_id' => $campaignId, 'rule_type' => 'attribute', 'taxonomy' => $rule['taxonomy'], 'term_id' => $rule['term_id']];
        }

        foreach ($dto->brandIds as $termId) {
            $rows[] = [
                'campaign_id' => $campaignId,
                'rule_type'   => 'brand',
                'taxonomy'    => 'product_brand',   // primary; getBrands() returns actual taxonomy
                'term_id'     => $termId
            ];
        }

        foreach ($rows as $row) {
            $wpdb->insert($table, $row, ['%d', '%s', '%s', '%d']);
        }
    }

    private function deleteProducts(int $campaignId): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'cmc_campaign_products', ['campaign_id' => $campaignId], ['%d']);
    }

    private function deleteRules(int $campaignId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cmc_campaign_rules';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $wpdb->delete($table, ['campaign_id' => $campaignId], ['%d']);
        }
    }
}