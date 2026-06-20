<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Repositories;

/**
 * Campaign Sales Repository
 *
 * Owns the wp_cmc_campaign_sales event-log table — the single, accurate
 * source of truth for the Reports section. Every row is a snapshot taken
 * at order-creation time (see CampaignSalesRecorder), so reporting never
 * has to guess whether a product was under a campaign at sale time.
 *
 * Only orders in a "paid" status (processing/completed) are counted in
 * the aggregate report queries; order_status is kept in sync with the
 * live WooCommerce order by the recorder.
 *
 * sold_at is stored in SITE-LOCAL time, so all range filters below
 * compare directly against the site-local Jalali ranges the UI produces.
 *
 * @package Msi\Campaignchi\Analytics\Repositories
 */
class CampaignSalesRepository
{
    /** Order statuses that count as a real, finalized sale. */
    public const PAID_STATUSES = ['processing', 'completed'];

    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cmc_campaign_sales';
    }

    // -------------------------------------------------------
    // WRITE
    // -------------------------------------------------------

    /**
     * Insert (or refresh) a single campaign-sale snapshot row.
     * Idempotent via the UNIQUE(order_id, product_id, campaign_id) key, so
     * re-firing the checkout/new-order hooks can never create duplicates.
     */
    public function record(
        int $orderId,
        int $campaignId,
        int $productId,
        int $qty,
        float $revenue,
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
            current_time('mysql', true)
        ));
    }

    /** Keep the cached order_status in sync when the WooCommerce order transitions. */
    public function updateOrderStatus(int $orderId, string $status): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['order_status' => $status],
            ['order_id' => $orderId],
            ['%s'],
            ['%d']
        );
    }

    /** Whether any snapshot row already exists for a given order. */
    public function hasOrder(int $orderId): bool
    {
        global $wpdb;

        $table = $this->table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE order_id = %d",
            $orderId
        )) > 0;
    }

    // -------------------------------------------------------
    // READ — aggregates for the Reports section
    // -------------------------------------------------------

    /**
     * Overall totals for a range.
     *
     * @return array{revenue: float, orders: int, qty: int}
     */
    public function getSummary(string $start, string $end): array
    {
        global $wpdb;

        $table = $this->table();
        [$statusIn, $params] = $this->paidStatusClause();
        $params[] = $start . ' 00:00:00';
        $params[] = $end . ' 23:59:59';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(revenue), 0) AS revenue,
                    COUNT(DISTINCT order_id)  AS orders,
                    COALESCE(SUM(qty), 0)     AS qty
             FROM {$table}
             WHERE order_status IN ({$statusIn})
               AND sold_at BETWEEN %s AND %s",
            ...$params
        ), ARRAY_A);

        return [
            'revenue' => (float) ($row['revenue'] ?? 0),
            'orders'  => (int) ($row['orders'] ?? 0),
            'qty'     => (int) ($row['qty'] ?? 0),
        ];
    }

    /**
     * Daily revenue/orders, keyed by Y-m-d. Gap days are simply absent and
     * are filled in by the caller while building the chart series.
     *
     * @return array<string, array{revenue: float, orders: int}>
     */
    public function getDailySeries(string $start, string $end): array
    {
        global $wpdb;

        $table = $this->table();
        [$statusIn, $params] = $this->paidStatusClause();
        $params[] = $start . ' 00:00:00';
        $params[] = $end . ' 23:59:59';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(sold_at) AS d,
                    COALESCE(SUM(revenue), 0) AS revenue,
                    COUNT(DISTINCT order_id)  AS orders
             FROM {$table}
             WHERE order_status IN ({$statusIn})
               AND sold_at BETWEEN %s AND %s
             GROUP BY DATE(sold_at)",
            ...$params
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['d']] = [
                'revenue' => (float) $row['revenue'],
                'orders'  => (int) $row['orders'],
            ];
        }

        return $out;
    }

    /**
     * Per-campaign breakdown for a range.
     *
     * @return array<int, array{campaign_id:int, revenue:float, orders:int, qty:int}>
     */
    public function getByCampaign(string $start, string $end): array
    {
        global $wpdb;

        $table = $this->table();
        [$statusIn, $params] = $this->paidStatusClause();
        $params[] = $start . ' 00:00:00';
        $params[] = $end . ' 23:59:59';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT campaign_id,
                    COALESCE(SUM(revenue), 0) AS revenue,
                    COUNT(DISTINCT order_id)  AS orders,
                    COALESCE(SUM(qty), 0)     AS qty
             FROM {$table}
             WHERE order_status IN ({$statusIn})
               AND sold_at BETWEEN %s AND %s
             GROUP BY campaign_id
             ORDER BY revenue DESC",
            ...$params
        ), ARRAY_A) ?: [];

        return array_map(static fn ($row) => [
            'campaign_id' => (int) $row['campaign_id'],
            'revenue'     => (float) $row['revenue'],
            'orders'      => (int) $row['orders'],
            'qty'         => (int) $row['qty'],
        ], $rows);
    }

    /**
     * Best-selling products (by quantity) in a range.
     *
     * @return array<int, array{product_id:int, qty:int, revenue:float, campaign_id:int}>
     */
    public function getTopProducts(string $start, string $end, int $limit = 50): array
    {
        global $wpdb;

        $table = $this->table();
        [$statusIn, $params] = $this->paidStatusClause();
        $params[] = $start . ' 00:00:00';
        $params[] = $end . ' 23:59:59';
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
            'product_id'  => (int) $row['product_id'],
            'qty'         => (int) $row['qty'],
            'revenue'     => (float) $row['revenue'],
            'campaign_id' => (int) $row['campaign_id'],
        ], $rows);
    }

    /**
     * One aggregated row PER ORDER for the CSV export (order-centric).
     * GROUP_CONCAT collects the distinct campaign/product ids involved in
     * each order; the service maps those ids to human titles/names.
     *
     * @return array<int, array{
     *   order_id:int, sold_at:string, customer_name:string, customer_email:string,
     *   qty:int, revenue:float, campaign_ids:string, product_ids:string, order_status:string
     * }>
     */
    public function getOrderRows(string $start, string $end): array
    {
        global $wpdb;

        $table = $this->table();
        [$statusIn, $params] = $this->paidStatusClause();
        $params[] = $start . ' 00:00:00';
        $params[] = $end . ' 23:59:59';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT order_id,
                    MAX(sold_at)        AS sold_at,
                    MAX(customer_name)  AS customer_name,
                    MAX(customer_email) AS customer_email,
                    MAX(order_status)   AS order_status,
                    COALESCE(SUM(qty), 0)     AS qty,
                    COALESCE(SUM(revenue), 0) AS revenue,
                    GROUP_CONCAT(DISTINCT campaign_id) AS campaign_ids,
                    GROUP_CONCAT(DISTINCT product_id)  AS product_ids
             FROM {$table}
             WHERE order_status IN ({$statusIn})
               AND sold_at BETWEEN %s AND %s
             GROUP BY order_id
             ORDER BY sold_at DESC",
            ...$params
        ), ARRAY_A) ?: [];

        return array_map(static fn ($row) => [
            'order_id'       => (int) $row['order_id'],
            'sold_at'        => (string) $row['sold_at'],
            'customer_name'  => (string) $row['customer_name'],
            'customer_email' => (string) $row['customer_email'],
            'order_status'   => (string) $row['order_status'],
            'qty'            => (int) $row['qty'],
            'revenue'        => (float) $row['revenue'],
            'campaign_ids'   => (string) $row['campaign_ids'],
            'product_ids'    => (string) $row['product_ids'],
        ], $rows);
    }

    /**
     * Build the "order_status IN (%s, %s, ...)" fragment and its bound
     * params, so every aggregate query treats "paid" identically.
     *
     * @return array{0:string, 1:array<int, string>}
     */
    private function paidStatusClause(): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::PAID_STATUSES), '%s'));

        return [$placeholders, array_values(self::PAID_STATUSES)];
    }
}