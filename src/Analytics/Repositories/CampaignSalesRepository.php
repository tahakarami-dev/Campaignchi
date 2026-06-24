<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Repositories;

/**
 * Campaign Sales Repository
 *
 * Owns the `wp_cmc_campaign_sales` event-log table — the single source of
 * truth for the Reports section.
 *
 * TABLE SCHEMA (reference):
 *   id            INT AUTO_INCREMENT PRIMARY KEY
 *   order_id      INT NOT NULL
 *   campaign_id   INT NOT NULL
 *   product_id    INT NOT NULL
 *   qty           INT NOT NULL
 *   revenue       DECIMAL(15,4) NOT NULL   -- line total after discount, excl. tax
 *   customer_name VARCHAR(255)
 *   customer_email VARCHAR(255)
 *   order_status  VARCHAR(50)
 *   sold_at       DATETIME                 -- SITE-LOCAL timezone
 *   created_at    DATETIME
 *   UNIQUE KEY uq_order_product_campaign (order_id, product_id, campaign_id)
 *
 * DATA SCOPE:
 *   Every row represents ONE campaign-attributed line item inside ONE order.
 *   Regular (non-campaign) line items inside the same order are NEVER stored
 *   here. Revenue figures therefore reflect only campaign products — never
 *   the full WooCommerce order total.
 *
 * REVENUE INTEGRITY:
 *   - revenue per row = item->get_total() at checkout = post-discount, excl. tax
 *   - SUM(revenue) across rows gives the total campaign-attributed revenue
 *   - COUNT(DISTINCT order_id) gives orders that contained ≥1 campaign item
 *   - These two numbers are consistent and do NOT double-count orders that
 *     also contained non-campaign items.
 *
 * TIMEZONE:
 *   sold_at is stored in SITE-LOCAL time (WP timezone), so all date range
 *   comparisons in the queries below work directly with site-local Y-m-d strings.
 *
 * @package Msi\Campaignchi\Analytics\Repositories
 */
class CampaignSalesRepository
{
    /**
     * WooCommerce order statuses that count as a finalized, paid sale.
     * Only rows with one of these statuses are included in report aggregates.
     *
     * @var string[]
     */
    public const PAID_STATUSES = ['processing', 'completed'];

    // =========================================================
    // TABLE HELPER
    // =========================================================

    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cmc_campaign_sales';
    }

    // =========================================================
    // WRITE OPERATIONS
    // =========================================================

    /**
     * Insert or refresh a single campaign-sale snapshot row.
     *
     * The UNIQUE(order_id, product_id, campaign_id) constraint makes this
     * idempotent: duplicate hook fires never produce duplicate rows.
     * ON DUPLICATE KEY UPDATE only refreshes mutable fields (qty, revenue,
     * customer data, status) — campaign attribution is immutable once set.
     *
     * @param int    $orderId       WooCommerce order ID
     * @param int    $campaignId    Campaign that was active at checkout
     * @param int    $productId     Parent product ID (variation resolved to parent)
     * @param int    $qty           Quantity purchased
     * @param float  $revenue       Line total after campaign discount, excl. tax
     * @param string $customerName  Billing first + last name
     * @param string $customerEmail Billing email
     * @param string $orderStatus   WooCommerce status at time of snapshot
     * @param string $soldAt        Y-m-d H:i:s in SITE-LOCAL timezone
     */
    public function record(
        int    $orderId,
        int    $campaignId,
        int    $productId,
        int    $qty,
        float  $revenue,
        string $customerName,
        string $customerEmail,
        string $orderStatus,
        string $soldAt
    ): void {
        global $wpdb;

        $table = $this->table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (order_id, campaign_id, product_id, qty, revenue,
                 customer_name, customer_email, order_status, sold_at, created_at)
             VALUES (%d, %d, %d, %d, %f, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                qty            = VALUES(qty),
                revenue        = VALUES(revenue),
                customer_name  = VALUES(customer_name),
                customer_email = VALUES(customer_email),
                order_status   = VALUES(order_status)",
            $orderId,
            $campaignId,
            $productId,
            $qty,
            $revenue,
            $customerName,
            $customerEmail,
            $orderStatus,
            $soldAt,
            current_time('mysql', true) // created_at stored in UTC
        ));
    }

    /**
     * Sync the cached order_status whenever a WooCommerce order transitions.
     *
     * Called by CampaignSalesRecorder::onStatusChanged() on every status change.
     * Keeping this in sync ensures PAID_STATUSES filters remain accurate even
     * after the snapshot was originally taken at a pending/processing state.
     *
     * @param int    $orderId WooCommerce order ID
     * @param string $status  New WooCommerce status (without "wc-" prefix)
     */
    public function updateOrderStatus(int $orderId, string $status): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['order_status' => $status],
            ['order_id'     => $orderId],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Check whether any snapshot row already exists for the given order.
     *
     * Used by the safety-net in CampaignSalesRecorder::onStatusChanged() to
     * avoid unnecessary snapshot attempts for orders we already processed.
     *
     * @param int $orderId WooCommerce order ID
     */
    public function hasOrder(int $orderId): bool
    {
        global $wpdb;

        $table = $this->table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE order_id = %d LIMIT 1",
            $orderId
        )) > 0;
    }

    // =========================================================
    // READ — aggregate queries for the Reports section
    // =========================================================

    /**
     * Overall totals for a date range.
     *
     * Revenue = sum of ONLY campaign-attributed line item totals (NOT full
     * order totals). Orders = count of DISTINCT orders that contained at
     * least one campaign item. Both numbers are consistent with each other
     * and with per-campaign breakdowns from getByCampaign().
     *
     * @param string $start Y-m-d start date (site-local, inclusive)
     * @param string $end   Y-m-d end date   (site-local, inclusive)
     * @return array{revenue: float, orders: int, qty: int}
     */
    public function getSummary(string $start, string $end): array
    {
        global $wpdb;

        $table = $this->table();
        [$statusIn, $params] = $this->paidStatusClause();

        // Date range uses full-day boundaries in site-local time.
        $params[] = $start . ' 00:00:00';
        $params[] = $end   . ' 23:59:59';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(revenue), 0)   AS revenue,
                    COUNT(DISTINCT order_id)     AS orders,
                    COALESCE(SUM(qty), 0)        AS qty
             FROM {$table}
             WHERE order_status IN ({$statusIn})
               AND sold_at BETWEEN %s AND %s",
            ...$params
        ), ARRAY_A);

        return [
            'revenue' => (float) ($row['revenue'] ?? 0),
            'orders'  => (int)   ($row['orders']  ?? 0),
            'qty'     => (int)   ($row['qty']     ?? 0),
        ];
    }

    /**
     * Daily revenue and order counts for chart series, keyed by Y-m-d.
     *
     * Gap days (no campaign sales) are absent from the returned array and
     * must be filled with zeros by the caller (ReportService::buildSeries).
     *
     * @param string $start Y-m-d start date (site-local, inclusive)
     * @param string $end   Y-m-d end date   (site-local, inclusive)
     * @return array<string, array{revenue: float, orders: int}>
     */
    public function getDailySeries(string $start, string $end): array
    {
        global $wpdb;

        $table = $this->table();
        [$statusIn, $params] = $this->paidStatusClause();
        $params[] = $start . ' 00:00:00';
        $params[] = $end   . ' 23:59:59';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(sold_at)                AS d,
                    COALESCE(SUM(revenue), 0)   AS revenue,
                    COUNT(DISTINCT order_id)     AS orders
             FROM {$table}
             WHERE order_status IN ({$statusIn})
               AND sold_at BETWEEN %s AND %s
             GROUP BY DATE(sold_at)
             ORDER BY DATE(sold_at) ASC",
            ...$params
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['d']] = [
                'revenue' => (float) $row['revenue'],
                'orders'  => (int)   $row['orders'],
            ];
        }

        return $out;
    }

    /**
     * Per-campaign revenue / orders / qty breakdown for a date range.
     *
     * Results are sorted by revenue DESC so the most impactful campaign
     * appears first in the reports table.
     *
     * @param string $start Y-m-d start date (site-local, inclusive)
     * @param string $end   Y-m-d end date   (site-local, inclusive)
     * @return array<int, array{campaign_id: int, revenue: float, orders: int, qty: int}>
     */
    public function getByCampaign(string $start, string $end): array
    {
        global $wpdb;

        $table = $this->table();
        [$statusIn, $params] = $this->paidStatusClause();
        $params[] = $start . ' 00:00:00';
        $params[] = $end   . ' 23:59:59';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT campaign_id,
                    COALESCE(SUM(revenue), 0)   AS revenue,
                    COUNT(DISTINCT order_id)     AS orders,
                    COALESCE(SUM(qty), 0)        AS qty
             FROM {$table}
             WHERE order_status IN ({$statusIn})
               AND sold_at BETWEEN %s AND %s
             GROUP BY campaign_id
             ORDER BY revenue DESC",
            ...$params
        ), ARRAY_A) ?: [];

        return array_map(static fn ($row) => [
            'campaign_id' => (int)   $row['campaign_id'],
            'revenue'     => (float) $row['revenue'],
            'orders'      => (int)   $row['orders'],
            'qty'         => (int)   $row['qty'],
        ], $rows);
    }

    /**
     * Best-selling campaign products (by quantity) in a date range.
     *
     * Each row represents a distinct product_id. Revenue and qty are summed
     * across all campaigns and orders that included that product.
     * campaign_id = the MOST RECENTLY associated campaign (MAX) — a product
     * may appear in multiple campaigns over time; this picks the latest one
     * to display a meaningful label in the top-products table.
     *
     * @param string $start Y-m-d start date (site-local, inclusive)
     * @param string $end   Y-m-d end date   (site-local, inclusive)
     * @param int    $limit Maximum rows to return (default 50; page shows 10)
     * @return array<int, array{product_id: int, qty: int, revenue: float, campaign_id: int}>
     */
    public function getTopProducts(string $start, string $end, int $limit = 50): array
    {
        global $wpdb;

        $table = $this->table();
        [$statusIn, $params] = $this->paidStatusClause();
        $params[] = $start . ' 00:00:00';
        $params[] = $end   . ' 23:59:59';
        $params[] = max(1, $limit);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id,
                    COALESCE(SUM(qty), 0)     AS qty,
                    COALESCE(SUM(revenue), 0) AS revenue,
                    MAX(campaign_id)          AS campaign_id
             FROM {$table}
             WHERE order_status IN ({$statusIn})
               AND sold_at BETWEEN %s AND %s
             GROUP BY product_id
             ORDER BY qty DESC
             LIMIT %d",
            ...$params
        ), ARRAY_A) ?: [];

        return array_map(static fn ($row) => [
            'product_id'  => (int)   $row['product_id'],
            'qty'         => (int)   $row['qty'],
            'revenue'     => (float) $row['revenue'],
            'campaign_id' => (int)   $row['campaign_id'],
        ], $rows);
    }

    /**
     * One aggregated row PER ORDER for the CSV export.
     *
     * Each row represents a single WooCommerce order that contained at least
     * one campaign-attributed item. Revenue = SUM of ONLY campaign item
     * totals (NOT the full order total — the order may have also contained
     * non-campaign products). qty = SUM of campaign item quantities only.
     *
     * GROUP_CONCAT collects distinct campaign_ids / product_ids per order so
     * the service layer can resolve them to human-readable titles/names.
     *
     * @param string $start Y-m-d start date (site-local, inclusive)
     * @param string $end   Y-m-d end date   (site-local, inclusive)
     * @return array<int, array{
     *   order_id: int, sold_at: string, customer_name: string, customer_email: string,
     *   order_status: string, qty: int, revenue: float,
     *   campaign_ids: string, product_ids: string
     * }>
     */
    public function getOrderRows(string $start, string $end): array
    {
        global $wpdb;

        $table = $this->table();
        [$statusIn, $params] = $this->paidStatusClause();
        $params[] = $start . ' 00:00:00';
        $params[] = $end   . ' 23:59:59';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT order_id,
                    MAX(sold_at)                               AS sold_at,
                    MAX(customer_name)                         AS customer_name,
                    MAX(customer_email)                        AS customer_email,
                    MAX(order_status)                          AS order_status,
                    COALESCE(SUM(qty), 0)                      AS qty,
                    COALESCE(SUM(revenue), 0)                  AS revenue,
                    GROUP_CONCAT(DISTINCT campaign_id ORDER BY campaign_id) AS campaign_ids,
                    GROUP_CONCAT(DISTINCT product_id  ORDER BY product_id)  AS product_ids
             FROM {$table}
             WHERE order_status IN ({$statusIn})
               AND sold_at BETWEEN %s AND %s
             GROUP BY order_id
             ORDER BY sold_at DESC",
            ...$params
        ), ARRAY_A) ?: [];

        return array_map(static fn ($row) => [
            'order_id'       => (int)    $row['order_id'],
            'sold_at'        => (string) $row['sold_at'],
            'customer_name'  => (string) $row['customer_name'],
            'customer_email' => (string) $row['customer_email'],
            'order_status'   => (string) $row['order_status'],
            'qty'            => (int)    $row['qty'],
            'revenue'        => (float)  $row['revenue'],
            'campaign_ids'   => (string) $row['campaign_ids'],
            'product_ids'    => (string) $row['product_ids'],
        ], $rows);
    }

    // =========================================================
    // PRIVATE HELPERS
    // =========================================================

    /**
     * Build the "order_status IN (%s, %s, ...)" SQL fragment and its
     * corresponding bound parameter values from PAID_STATUSES.
     *
     * Centralizing this ensures every aggregate query uses the exact same
     * paid-status definition — no risk of one query counting cancelled orders
     * while another doesn't.
     *
     * @return array{0: string, 1: string[]}  [placeholder_string, bound_values]
     */
    private function paidStatusClause(): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::PAID_STATUSES), '%s'));

        return [$placeholders, array_values(self::PAID_STATUSES)];
    }
}