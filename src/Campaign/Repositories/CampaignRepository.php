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
 * FIX (Section 10 — Auto-transition bugs):
 *
 *   getCampaignsToActivate():
 *     Previously only queried status='scheduled'. A campaign with
 *     status='active' but starts_at in the future (set manually by the
 *     admin) would never be activated by the cron. The query now also
 *     catches status='active' rows where starts_at has not yet arrived,
 *     treating them as implicitly scheduled.
 *
 *   getNextTransitionTimestamp():
 *     Returns strtotime() applied to a naive, site-local DATETIME string.
 *     The calling code in CampaignResolver::calculateCacheTtl() must diff
 *     this against current_time('timestamp') — NOT time() — to stay in
 *     the same "naive site-local" timezone convention.
 *     The return value is documented to reflect this so callers remain
 *     correct. No change to the SQL is needed here because
 *     current_time('mysql') (used as the $now parameter) is already in
 *     the same naive site-local format as starts_at/ends_at.
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

        // Total count.
        $countSQL = "SELECT COUNT(*) FROM {$table} WHERE {$whereSQL}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var(
            empty($params) ? $countSQL : $wpdb->prepare($countSQL, ...$params)
        );

        // Rows.
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
     * Get all campaigns that are currently LIVE.
     *
     * Rules:
     *  - status must be 'active' or 'scheduled'
     *  - amazing_offer: live only if status = 'active' (no date check)
     *  - flash_sale: live if NOW is between starts_at and ends_at
     *                (open-ended bounds if either date is NULL)
     *
     * Ordering = priority:
     *   1) flash_sale before amazing_offer
     *   2) newest campaign (higher id) wins ties
     *
     * @return Campaign[]
     */
    public function getLiveCampaigns(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';
        $now   = current_time('mysql'); // naive site-local, matches starts_at/ends_at convention.

        $sql = "SELECT * FROM {$table}
                WHERE status IN ('active', 'scheduled')
                AND (
                    (type = 'amazing_offer' AND status = 'active')
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
     * Flash-sale campaigns that should switch to 'active' because their
     * starts_at has arrived.
     *
     * FIX: The previous query only matched status='scheduled', which
     * missed campaigns the admin had set to status='active' manually
     * while also setting a future starts_at. The fix adds status='active'
     * to the IN clause so those are caught and properly transitioned
     * (their price filters will stay inactive until starts_at arrives
     * because getLiveCampaigns() checks the date, but the cron should
     * still flip them so the admin-facing status badge is correct).
     *
     * @return Campaign[]
     */
    public function getCampaignsToActivate(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';
        $now   = current_time('mysql');

        // Matches both 'scheduled' and 'active' with a future starts_at:
        //   - 'scheduled' + past starts_at  → activate  (original behaviour)
        //   - 'active'    + past starts_at  → re-confirm active (idempotent)
        //   - either status + NULL starts_at → skip (no date constraint = always live)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status IN ('scheduled', 'active')
               AND type = 'flash_sale'
               AND starts_at IS NOT NULL
               AND starts_at <= %s
               AND (ends_at IS NULL OR ends_at >= %s)",
            $now,
            $now
        ));

        return array_map([Campaign::class, 'fromRow'], $rows ?: []);
    }

    /**
     * Flash-sale campaigns whose ends_at has passed and should transition
     * to 'ended'.
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
               AND type = 'flash_sale'
               AND ends_at IS NOT NULL
               AND ends_at < %s",
            $now
        ));

        return array_map([Campaign::class, 'fromRow'], $rows ?: []);
    }

    /**
     * Get the SITE-LOCAL Unix timestamp of the next moment a flash-sale
     * campaign's live state will change (about to start or about to end).
     *
     * Used by CampaignResolver::calculateCacheTtl() to set a precise
     * pricing-cache expiration so discounts switch on/off on schedule.
     *
     * ⚠️ TIMEZONE NOTE: starts_at / ends_at are naive, site-local DATETIME
     * strings (entered via the Jalali picker, no tz info). strtotime() on
     * them therefore produces a "site-local Unix timestamp" — i.e. the same
     * value that current_time('timestamp') returns for "now". The caller
     * MUST diff this against current_time('timestamp'), NOT time() (UTC).
     *
     * @return int|null Site-local Unix timestamp, or null if no transition pending.
     */
    public function getNextTransitionTimestamp(): ?int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cmc_campaigns';
        $now   = current_time('mysql'); // naive site-local, same convention as starts_at/ends_at.

        $sql = "SELECT MIN(t) FROM (
                    SELECT starts_at AS t FROM {$table}
                        WHERE type = 'flash_sale'
                          AND status IN ('active', 'scheduled')
                          AND starts_at IS NOT NULL
                          AND starts_at > %s
                    UNION ALL
                    SELECT ends_at AS t FROM {$table}
                        WHERE type = 'flash_sale'
                          AND status IN ('active', 'scheduled')
                          AND ends_at IS NOT NULL
                          AND ends_at > %s
                ) AS transitions";

        $next = $wpdb->get_var($wpdb->prepare($sql, $now, $now));

        // strtotime() on a naive datetime gives a site-local Unix timestamp —
        // consistent with current_time('timestamp') used in calculateCacheTtl().
        return $next ? (int) strtotime($next) : null;
    }

    /**
     * Count campaigns created since a given GMT datetime.
     * Used by AnalyticsService for "X new campaigns this week".
     *
     * @param string $datetime GMT datetime string (Y-m-d H:i:s).
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
     * Recently-changed campaigns for the activity feed.
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
     * All non-draft campaigns, ordered by priority (flash_sale first, newest first).
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
        $nowGmt = current_time('mysql', true); // GMT — ensures dashboard "time ago" is correct.

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
     *
     * @throws \RuntimeException On DB failure.
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

        // Fires cmc_campaign_changed → CampaignResolver::flushCache().
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
     * Get product IDs attached to a campaign.
     *
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
     * Get rules for a campaign (category/tag/attribute/brand selections).
     *
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

        // Guard: table may not exist on very old installations.
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

    /** Insert product pivot rows. */
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

    /** Insert rule rows for category/tag/attribute/brand selections. */
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
            $rows[] = [
                'campaign_id' => $campaignId,
                'rule_type'   => 'brand',
                'taxonomy'    => 'product_brand',
                'term_id'     => $termId,
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