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
 * FIX — Section 10: Auto-transition rules
 *
 *  Rule 1 (amazing_offer + scheduled):
 *    amazing_offer campaigns NEVER get status='scheduled'. They have no
 *    starts_at / ends_at, so scheduling is meaningless. If the admin
 *    picks "scheduled" on an amazing_offer the CampaignService now
 *    silently overrides it to 'active' (see CampaignService). At the
 *    repository level, getLiveCampaigns() only returns amazing_offer rows
 *    whose status='active' — nothing changes here.
 *
 *  Rule 2 (auto-set status on save):
 *    CreateCampaignDTO / CampaignService now derive the correct initial
 *    status from the dates the user supplied:
 *      - starts_at in the future  → status = 'scheduled'  (even if admin
 *                                   picked 'active' manually)
 *      - starts_at in past/absent → status stays as chosen
 *    This logic lives in CampaignService, not here.
 *
 *  Rule 3 (getCampaignsToActivate — both types):
 *    Previously type='flash_sale' only. Now includes all types that have
 *    status='scheduled' and a starts_at that has arrived, so the cron
 *    sets them to 'active' at the right moment.
 *
 *  Rule 4 (getCampaignsToExpire — respects auto_expire_status setting):
 *    PricingServiceProvider::processAutoTransitions() now reads the
 *    SettingsPage::getCampaign()['auto_expire_status'] value ('ended' or
 *    'draft') and passes it to updateStatus(). The repository itself just
 *    stores whatever status string it receives.
 *
 *  Rule 5 (getNextTransitionTimestamp — all types):
 *    The query no longer filters on type='flash_sale' so that amazing_offer
 *    campaigns with a starts_at are also included in the TTL calculation.
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
     *   string $status   (optional)
     *   string $search   (optional)
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

        $countSQL = "SELECT COUNT(*) FROM {$table} WHERE {$whereSQL}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var(
            empty($params) ? $countSQL : $wpdb->prepare($countSQL, ...$params)
        );

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
     * Get a single campaign by ID.
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
     * Get all campaigns that are currently LIVE (pricing engine uses this).
     *
     * "Live" means the campaign's discount should be applied RIGHT NOW:
     *   - Any type:         status must be 'active'
     *   - flash_sale only:  additionally starts_at ≤ now AND ends_at ≥ now
     *                       (NULL on either side = open-ended bound)
     *   - amazing_offer:    no date check — live whenever status='active'
     *
     * 'scheduled' rows are never live — they are waiting for starts_at to
     * arrive, after which the cron flips them to 'active'.
     *
     * Ordering priority:
     *   1) flash_sale before amazing_offer
     *   2) newest campaign (higher id) wins ties
     *
     * @return Campaign[]
     */
    public function getLiveCampaigns(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';
        $now   = current_time('mysql'); // naive site-local, matches starts_at/ends_at.

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
     * Campaigns that should switch to 'active' because their starts_at has arrived.
     *
     * FIX: No longer restricted to type='flash_sale'. Any campaign with
     * status='scheduled' whose starts_at ≤ now should be activated.
     * Also: amazing_offer campaigns should never reach this state because
     * CampaignService prevents assigning status='scheduled' to them — but
     * if they somehow end up here, this query will still handle them safely.
     *
     * @return Campaign[]
     */
    public function getCampaignsToActivate(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';
        $now   = current_time('mysql');

        // Match ALL types: any scheduled campaign whose start time has arrived.
        // ends_at guard: skip campaigns that started AND already ended in the
        // same cron cycle (rare but possible if the cron was missed).
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
     * Campaigns whose ends_at has passed and should be expired.
     *
     * FIX: No longer restricted to type='flash_sale'. Any active/scheduled
     * campaign with a past ends_at should expire. The target status
     * ('ended' or 'draft') is decided by the caller
     * (PricingServiceProvider::processAutoTransitions), which reads the
     * SettingsPage::getCampaign()['auto_expire_status'] option.
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
     * FIX: Removed the type='flash_sale' restriction so amazing_offer
     * campaigns with dates also contribute to the TTL calculation.
     *
     * ⚠️ TIMEZONE NOTE: returns strtotime() of a naive site-local DATETIME.
     * Callers must diff against current_time('timestamp'), NOT time().
     *
     * @return int|null Site-local Unix timestamp, or null if no pending transition.
     */
    public function getNextTransitionTimestamp(): ?int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';
        $now   = current_time('mysql');

        // FIX: no type filter — all campaign types with scheduled dates matter.
        $sql = "SELECT MIN(t) FROM (
                    SELECT starts_at AS t FROM {$table}
                        WHERE status IN ('active', 'scheduled')
                          AND starts_at IS NOT NULL
                          AND starts_at > %s
                    UNION ALL
                    SELECT ends_at AS t FROM {$table}
                        WHERE status IN ('active', 'scheduled')
                          AND ends_at IS NOT NULL
                          AND ends_at > %s
                ) AS transitions";

        $next = $wpdb->get_var($wpdb->prepare($sql, $now, $now));

        return $next ? (int) strtotime($next) : null;
    }

    /**
     * Count campaigns created since a given GMT datetime.
     * Used by AnalyticsService for "X new campaigns this week".
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
     * Recently-changed campaigns (for activity feed).
     *
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
     * All non-draft campaigns, ordered by priority.
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
     * Insert a new campaign from DTO.
     *
     * @throws \RuntimeException On DB failure.
     */
    public function create(CreateCampaignDTO $dto): int
    {
        global $wpdb;

        $table  = $wpdb->prefix . 'cmc_campaigns';
        $nowGmt = current_time('mysql', true);

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
            throw new \RuntimeException('[CMC] Failed to insert campaign.');
        }

        $id = (int) $wpdb->insert_id;

        $this->syncProducts($id, $dto);
        $this->syncRules($id, $dto);

        do_action('cmc_campaign_changed', $id);

        return $id;
    }

    /**
     * Update an existing campaign from DTO.
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

        $this->deleteProducts($id);
        $this->deleteRules($id);
        $this->syncProducts($id, $dto);
        $this->syncRules($id, $dto);

        do_action('cmc_campaign_changed', $id);
    }

    /**
     * Update only the status field (used by the auto-transition cron).
     *
     * @param string $status One of: draft | active | scheduled | ended
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

        do_action('cmc_campaign_changed', $id);
    }

    /**
     * Delete a campaign and all its related records.
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

        do_action('cmc_campaign_changed', $id);
    }

    // -------------------------------------------------------
    // PRODUCT IDs
    // -------------------------------------------------------

    /**
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
     * @return array{category_ids:int[], tag_ids:int[], brand_ids:int[], attribute_rules:array}
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

    private function syncRules(int $campaignId, CreateCampaignDTO $dto): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaign_rules';

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