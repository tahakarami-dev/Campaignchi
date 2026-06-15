<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Repositories;

/**
 * Analytics Repository
 *
 * مسئول ثبت و خواندن داده‌های جدول wp_cmc_campaign_stats.
 * فعلاً فقط ستون impressions استفاده می‌شود — فروش/سفارش/نرخ تبدیل
 * به‌صورت لایو از سفارش‌های ووکامرس محاسبه می‌شوند (AnalyticsService).
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
     * ثبت یک بازدید برای یک کمپین در تاریخ امروز.
     * با UNIQUE KEY(campaign_id, stat_date) به‌صورت atomic افزایش می‌یابد.
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
     * جمع کل بازدیدهای همه‌ی کمپین‌ها برای یک تاریخ مشخص (Y-m-d).
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
}