<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analytics Repository
 *
 * Reads and writes the wp_cmc_campaign_stats table.
 * Currently only the impressions column is used — sales/orders/conversion
 * rate are computed live from WooCommerce orders instead (AnalyticsService).
 *
 * @package Msi\Campaignchi\Analytics\Repositories
 */
class AnalyticsRepository
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cmc_campaign_stats';
    }

    /**
     * Record one impression for a campaign today.
     * Increments atomically via UNIQUE KEY(campaign_id, stat_date).
     */
    public function recordImpression(int $campaignId): void
    {
        global $wpdb;

        $table = $this->table();
        $date  = current_time('Y-m-d');

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (campaign_id, stat_date, impressions, clicks, orders, revenue)
             VALUES (%d, %s, 1, 0, 0, 0)
             ON DUPLICATE KEY UPDATE impressions = impressions + 1",
            $campaignId,
            $date
        ));
    }

    /**
     * Total impressions across all campaigns for a given date (Y-m-d).
     */
    public function getTotalImpressions(string $date): int
    {
        global $wpdb;
        $table = $this->table();

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(impressions), 0) FROM {$table} WHERE stat_date = %s",
            $date
        ));

        return (int) $value;
    }

    /**
     * Per-campaign impression totals over a date range (Y-m-d to Y-m-d),
     * grouped by campaign for the reports table.
     *
     * @return array<int, int> campaign_id => impressions
     */
    public function getImpressionsByCampaign(string $startDate, string $endDate): array
    {
        global $wpdb;
        $table = $this->table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT campaign_id, COALESCE(SUM(impressions), 0) AS imp
             FROM {$table}
             WHERE stat_date BETWEEN %s AND %s
             GROUP BY campaign_id",
            $startDate,
            $endDate
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['campaign_id']] = (int) $row['imp'];
        }

        return $out;
    }
}
