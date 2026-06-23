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
 * -----------------------------------------------------------------------
 * Section 10 — Auto-transition rules (revised)
 *
 *  Rule 1 (amazing_offer cannot be scheduled):
 *    amazing_offer campaigns NEVER get status='scheduled'. They have no
 *    starts_at / ends_at concept. CampaignService::applyStatusRules()
 *    overrides any attempt. At the repository level, getLiveCampaigns()
 *    only returns amazing_offer rows with status='active'.
 *
 *  Rule 2 (status derivation on save):
 *    CampaignService::applyStatusRules() derives the correct initial
 *    status from the dates the admin supplied:
 *      starts_at in the future  → status = 'scheduled'
 *      starts_at in past/absent → status stays as admin chose
 *
 *  Rule 3 (getCampaignsToActivate):
 *    Any campaign with status='scheduled' whose starts_at has arrived
 *    AND whose ends_at has NOT yet passed. Restricted to status='scheduled'
 *    only — prevents double-activation if cron runs early.
 *
 *  Rule 4 (getCampaignsToExpire):
 *    Any campaign in status 'active' or 'scheduled' whose ends_at < now.
 *    Target status ('ended'|'draft') is decided by the caller reading
 *    SettingsPage::getCampaign()['auto_expire_status'].
 *
 *  Rule 5 (getNextTransitionTimestamp):
 *    Returns the nearest future transition moment for ALL campaign types
 *    and statuses (scheduled + active) so the cache TTL is always correct.
 * -----------------------------------------------------------------------
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
     *   int    $per_page
     *   int    $page
     *   string $status   (optional filter)
     *   string $search   (optional keyword)
     *   string $orderby
     *   string $order    ASC|DESC
     * }
     * @return array{ items: Campaign[], total: int }
     */
    public function paginate(array $args = []): array
    {
        global $wpdb;

        $perPage = max(1, (int) ($args['per_page'] ?? 20));
        $page    = max(1, (int) ($args['page']     ?? 1));
        $offset  = ($page - 1) * $perPage;

        // Whitelist sortable columns to prevent SQL injection.
        $allowedOrderBy = ['title', 'status', 'created_at', 'starts_at'];
        $orderby = in_array($args['orderby'] ?? '', $allowedOrderBy, true)
            ? $args['orderby']
            : 'created_at';
        $order = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

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

        // Count query.
        $countSQL = "SELECT COUNT(*) FROM {$table} WHERE {$whereSQL}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var(
            empty($params) ? $countSQL : $wpdb->prepare($countSQL, ...$params)
        );

        // Data query.
        $rowSQL    = "SELECT * FROM {$table} WHERE {$whereSQL} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $allParams = array_merge($params, [$perPage, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($rowSQL, ...$allParams));

        return [
            'items' => array_map([Campaign::class, 'fromRow'], $rows ?: []),
            'total' => $total,
        ];
    }

    /**
     * Find a single campaign by its primary key.
     *
     * @param int $id Campaign ID.
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

    /**
     * Get all campaigns that are LIVE right now (used by the pricing engine).
     *
     * "Live" means the campaign's discount should be applied RIGHT NOW:
     *   - Any type:        status must be 'active'
     *   - flash_sale only: additionally starts_at ≤ now AND ends_at ≥ now
     *                      (NULL on either bound = open-ended)
     *   - amazing_offer:   no date check — live whenever status='active'
     *
     * 'scheduled' rows are never live. They are waiting for starts_at to
     * arrive, at which point the cron flips them to 'active'.
     *
     * Priority ordering:
     *   1) flash_sale before amazing_offer
     *   2) newest campaign (higher id) wins ties
     *
     * @return Campaign[]
     */
    public function getLiveCampaigns(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';

        // Use site-local time to match stored starts_at/ends_at values.
        $now = current_time('mysql');

        $sql = "SELECT * FROM {$table}
                WHERE status = 'active'
                  AND (
                      type = 'amazing_offer'
                      OR (
                          type = 'flash_sale'
                          AND (starts_at IS NULL OR starts_at <= %s)
                          AND (ends_at   IS NULL OR ends_at   >= %s)
                      )
                  )
                ORDER BY (type = 'flash_sale') DESC, id DESC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $now, $now));

        return array_map([Campaign::class, 'fromRow'], $rows ?: []);
    }

    /**
     * Find campaigns that should be activated now: scheduled → active.
     *
     * Conditions:
     *   - status = 'scheduled'  (never activates already-active campaigns)
     *   - starts_at IS NOT NULL AND starts_at <= now
     *   - ends_at IS NULL OR ends_at >= now
     *     (skip campaigns that started AND already ended — skip-expired guard)
     *
     * Both flash_sale and amazing_offer types are included, even though
     * CampaignService prevents amazing_offer from ever reaching 'scheduled'.
     * The guard here is a safety net.
     *
     * @return Campaign[]
     */
    public function getCampaignsToActivate(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';
        $now   = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'scheduled'
               AND starts_at IS NOT NULL
               AND starts_at <= %s
               AND (ends_at IS NULL OR ends_at >= %s)",
            $now,
            $now
        ));

        return array_map([Campaign::class, 'fromRow'], $rows ?: []);
    }

    /**
     * Find campaigns that should be expired now: (active|scheduled) → (ended|draft).
     *
     * Conditions:
     *   - status IN ('active', 'scheduled')
     *   - ends_at IS NOT NULL AND ends_at < now
     *
     * The target expiry status ('ended' or 'draft') is decided by the
     * caller (PricingServiceProvider::processAutoTransitions) which reads
     * the admin setting: SettingsPage::getCampaign()['auto_expire_status'].
     *
     * @return Campaign[]
     */
    public function getCampaignsToExpire(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';
        $now   = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status IN ('active', 'scheduled')
               AND ends_at IS NOT NULL
               AND ends_at < %s",
            $now
        ));

        return array_map([Campaign::class, 'fromRow'], $rows ?: []);
    }

    /**
     * Get the SITE-LOCAL Unix timestamp of the next moment any campaign's
     * live state will change (about to start or about to end).
     *
     * Used by CampaignResolver to set an optimal cache TTL so prices
     * refresh automatically without manual intervention.
     *
     * Considers:
     *   - starts_at of 'scheduled' campaigns whose start is still in the future
     *   - ends_at   of 'active'    campaigns whose end   is still in the future
     *
     * NOTE: We intentionally exclude 'active' starts_at here because a
     * campaign that is already active does not need to re-activate.
     *
     * ⚠️ TIMEZONE: returns strtotime() of a naive site-local DATETIME.
     * Callers MUST diff against current_time('timestamp'), NOT time().
     *
     * @return int|null Site-local Unix timestamp, or null if no pending transition.
     */
    public function getNextTransitionTimestamp(): ?int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';
        $now   = current_time('mysql');

        // Two sub-queries united:
        //   1. Next activation: scheduled campaigns whose starts_at is in the future.
        //   2. Next expiry:     active campaigns whose ends_at is in the future.
        $sql = "SELECT MIN(t) FROM (
                    SELECT starts_at AS t FROM {$table}
                        WHERE status = 'scheduled'
                          AND starts_at IS NOT NULL
                          AND starts_at > %s
                    UNION ALL
                    SELECT ends_at AS t FROM {$table}
                        WHERE status = 'active'
                          AND ends_at IS NOT NULL
                          AND ends_at > %s
                ) AS transitions";

        $next = $wpdb->get_var($wpdb->prepare($sql, $now, $now));

        return $next ? (int) strtotime($next) : null;
    }

    /**
     * Count campaigns created since a given GMT datetime.
     * Used by AnalyticsService for "X new campaigns this week" stats.
     *
     * @param string $datetime MySQL DATETIME in GMT.
     * @return int
     */
    public function countCreatedSince(string $datetime): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
            $datetime
        ));
    }

    /**
     * Get the most recently updated campaigns for the activity feed.
     *
     * @param int $limit Maximum number of records to return.
     * @return Campaign[]
     */
    public function getRecentlyChanged(int $limit = 5): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d",
            $limit
        ));

        return array_map([Campaign::class, 'fromRow'], $rows ?: []);
    }

    /**
     * Get all non-draft campaigns ordered by priority.
     * Used internally for analytics and reporting.
     *
     * @return Campaign[]
     */
    public function getNonDraftCampaigns(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';

        $rows = $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE status != 'draft'
             ORDER BY (type = 'flash_sale') DESC, id DESC"
        );

        return array_map([Campaign::class, 'fromRow'], $rows ?: []);
    }

    // -------------------------------------------------------
    // WRITE
    // -------------------------------------------------------

    /**
     * Insert a new campaign from a validated DTO.
     *
     * @param CreateCampaignDTO $dto Validated campaign data.
     * @throws \RuntimeException If the database insert fails.
     * @return int The newly created campaign ID.
     */
    public function create(CreateCampaignDTO $dto): int
    {
        global $wpdb;

        $table  = $wpdb->prefix . 'cmc_campaigns';
        $nowGmt = current_time('mysql', true); // GMT for audit timestamps.

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
            'created_at'     => $nowGmt,
            'updated_at'     => $nowGmt,
        ], ['%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        if (!$wpdb->insert_id) {
            throw new \RuntimeException('[CMC] Failed to insert campaign into database.');
        }

        $id = (int) $wpdb->insert_id;

        $this->syncProducts($id, $dto);
        $this->syncRules($id, $dto);

        // Notify the pricing engine to rebuild its cache.
        do_action('cmc_campaign_changed', $id);

        return $id;
    }

    /**
     * Update an existing campaign from a validated DTO.
     *
     * @param int               $id  Campaign ID to update.
     * @param CreateCampaignDTO $dto Validated campaign data.
     */
    public function update(int $id, CreateCampaignDTO $dto): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';

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
                'updated_at'     => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        // Rebuild product and rule associations.
        $this->deleteProducts($id);
        $this->deleteRules($id);
        $this->syncProducts($id, $dto);
        $this->syncRules($id, $dto);

        // Notify the pricing engine to rebuild its cache.
        do_action('cmc_campaign_changed', $id);
    }

    /**
     * Update only the status field (used by the auto-transition cron).
     *
     * @param int    $id     Campaign ID.
     * @param string $status Target status: draft | active | scheduled | ended
     */
    public function updateStatus(int $id, string $status): void
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'cmc_campaigns',
            [
                'status'     => $status,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        // Notify the pricing engine to flush affected product caches.
        do_action('cmc_campaign_changed', $id);
    }

    /**
     * Permanently delete a campaign and all its related records.
     *
     * @param int $id Campaign ID to delete.
     */
    public function delete(int $id): void
    {
        global $wpdb;

        // Remove child records first (no FK cascade in MySQL by default).
        $this->deleteProducts($id);
        $this->deleteRules($id);

        $wpdb->delete(
            $wpdb->prefix . 'cmc_campaigns',
            ['id' => $id],
            ['%d']
        );

        // Notify the pricing engine to flush all related caches.
        do_action('cmc_campaign_changed', $id);
    }

    // -------------------------------------------------------
    // PRODUCT IDs
    // -------------------------------------------------------

    /**
     * Get all manually-assigned product IDs for a campaign.
     *
     * @param int $campaignId Campaign ID.
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
     * Get all taxonomy/attribute rules for a campaign.
     *
     * @param int $campaignId Campaign ID.
     * @return array{
     *   category_ids:    int[],
     *   tag_ids:         int[],
     *   brand_ids:       int[],
     *   attribute_rules: array<array{taxonomy:string, term_id:int}>
     * }
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

        // Table may not exist on older installations — guard before querying.
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
                        'term_id'  => (int)    $row['term_id'],
                    ];
                    break;
            }
        }

        return $result;
    }

    // -------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------

    /**
     * Insert product IDs for a campaign (manual selection mode).
     *
     * @param int               $campaignId Campaign ID.
     * @param CreateCampaignDTO $dto        DTO containing productIds.
     */
    private function syncProducts(int $campaignId, CreateCampaignDTO $dto): void
    {
        global $wpdb;

        if (empty($dto->productIds)) {
            return;
        }

        $table = $wpdb->prefix . 'cmc_campaign_products';

        // De-duplicate before inserting to prevent constraint violations.
        foreach (array_unique($dto->productIds) as $productId) {
            $wpdb->insert(
                $table,
                ['campaign_id' => $campaignId, 'product_id' => $productId],
                ['%d', '%d']
            );
        }
    }

    /**
     * Insert taxonomy/attribute rules for a campaign.
     *
     * @param int               $campaignId Campaign ID.
     * @param CreateCampaignDTO $dto        DTO containing rule arrays.
     */
    private function syncRules(int $campaignId, CreateCampaignDTO $dto): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaign_rules';

        // Table may not exist on older installations — guard before inserting.
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
            $rows[] = ['campaign_id' => $campaignId, 'rule_type' => 'brand', 'taxonomy' => 'product_brand', 'term_id' => $termId];
        }

        foreach ($rows as $row) {
            $wpdb->insert($table, $row, ['%d', '%s', '%s', '%d']);
        }
    }

    /**
     * Delete all manually-assigned products for a campaign.
     *
     * @param int $campaignId Campaign ID.
     */
    private function deleteProducts(int $campaignId): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'cmc_campaign_products', ['campaign_id' => $campaignId], ['%d']);
    }

    /**
     * Delete all taxonomy/attribute rules for a campaign.
     *
     * @param int $campaignId Campaign ID.
     */
    private function deleteRules(int $campaignId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cmc_campaign_rules';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $wpdb->delete($table, ['campaign_id' => $campaignId], ['%d']);
        }
    }
}