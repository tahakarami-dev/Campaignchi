<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Controllers;

use Msi\Campaignchi\Admin\Pages\SettingsPage;
use Msi\Campaignchi\Campaign\Pricing\CampaignResolver;
use Msi\Campaignchi\Analytics\Services\AnalyticsService;

/**
 * Settings AJAX Controller
 *
 * Handles two admin-ajax actions:
 *
 *   cmc_save_settings_section  — persist one of the three settings groups
 *                                (general | campaign | performance).
 *   cmc_maintenance_action     — run a destructive/cleanup operation.
 *
 * Every value saved here is consumed by real code elsewhere in the plugin;
 * there are no write-only options. See SettingsPage for the consumer map.
 *
 * All handlers follow the same guard pattern as CampaignController:
 * verifyNonce() first, then json() to emit and exit.
 *
 * @package Msi\Campaignchi\Admin\Controllers
 */
class SettingsAjaxController
{
    public function register(): void
    {
        add_action('wp_ajax_cmc_save_settings_section', [$this, 'saveSection']);
        add_action('wp_ajax_cmc_maintenance_action', [$this, 'maintenanceAction']);
    }

    // -------------------------------------------------------
    // SAVE — one section at a time
    // -------------------------------------------------------

    public function saveSection(): void
    {
        $this->verifyNonce();

        $section = sanitize_key($_POST['section'] ?? '');
        $post    = wp_unslash($_POST);

        switch ($section) {
            case 'general':
                $this->saveGeneral($post);
                break;
            case 'campaign':
                $this->saveCampaign($post);
                break;
            case 'performance':
                $this->savePerformance($post);
                break;
            default:
                $this->json(['success' => false, 'message' => __('بخش نامعتبر است.', 'campaignchi')], 400);
        }
    }

    /**
     * @param array<string,mixed> $post
     */
    private function saveGeneral(array $post): void
    {
        $clean = [
            // Consumed by AdminServiceProvider::renderAdminBarBadge().
            'admin_bar_badge' => ($post['admin_bar_badge'] ?? '0') === '1',
        ];

        update_option(SettingsPage::OPT_GENERAL, $clean);
        $this->json(['success' => true, 'message' => __('تنظیمات عمومی ذخیره شد.', 'campaignchi')]);
    }

    /**
     * @param array<string,mixed> $post
     */
    private function saveCampaign(array $post): void
    {
        $interval = absint($post['cron_interval_minutes'] ?? 5);

        $clean = [
            // Discount ceilings — enforced in PriceCalculator::apply().
            'max_discount_percent'  => max(1, min(100, absint($post['max_discount_percent'] ?? 90))),
            'max_discount_fixed'    => max(0, absint($post['max_discount_fixed'] ?? 0)),
            // Cron interval — drives PricingServiceProvider's auto-transition schedule.
            'cron_interval_minutes' => in_array($interval, [5, 10, 15, 30], true) ? $interval : 5,
            // Expiry target status — read by PricingServiceProvider::processAutoTransitions().
            'auto_expire_status'    => in_array($post['auto_expire_status'] ?? '', ['ended', 'draft'], true)
                                        ? $post['auto_expire_status'] : 'ended',
        ];

        update_option(SettingsPage::OPT_CAMPAIGN, $clean);

        // Reschedule the cron so the new interval takes effect immediately.
        $this->rescheduleCron((int) $clean['cron_interval_minutes']);

        $this->json(['success' => true, 'message' => __('تنظیمات موتور کمپین ذخیره شد.', 'campaignchi')]);
    }

    /**
     * @param array<string,mixed> $post
     */
    private function savePerformance(array $post): void
    {
        $clean = [
            // Pricing-map TTL ceiling — read by CampaignResolver::calculateCacheTtl().
            'pricing_cache_ttl'    => max(20, min(3600, absint($post['pricing_cache_ttl'] ?? 300))),
            // Today-analytics TTL — read by AnalyticsService.
            'analytics_cache_ttl'  => max(10, min(600, absint($post['analytics_cache_ttl'] ?? 60))),
            // Campaign-candidates TTL — read by AnalyticsService.
            'candidates_cache_ttl' => max(60, min(3600, absint($post['candidates_cache_ttl'] ?? 600))),
        ];

        update_option(SettingsPage::OPT_PERFORMANCE, $clean);

        // Flush the pricing map so the new TTL is applied on the next rebuild.
        CampaignResolver::flushCache();

        $this->json(['success' => true, 'message' => __('تنظیمات پرفورمنس ذخیره شد.', 'campaignchi')]);
    }

    // -------------------------------------------------------
    // MAINTENANCE
    // -------------------------------------------------------

    public function maintenanceAction(): void
    {
        $this->verifyNonce();

        $actionType = sanitize_key($_POST['action_type'] ?? '');

        $result = match ($actionType) {
            'cleanup_old_stats'      => $this->cleanupOldStats(),
            'cleanup_orphaned_rules' => $this->cleanupOrphanedRules(),
            'cleanup_old_sales'      => $this->cleanupOldSales(),
            'flush_all_caches'       => $this->flushAllCaches(),
            'delete_ended_campaigns' => $this->deleteEndedCampaigns(),
            'factory_reset'          => $this->factoryReset(),
            default                  => ['success' => false, 'message' => __('عملیات نامعتبر.', 'campaignchi')],
        };

        $this->json($result);
    }

    /**
     * Remove analytics stat rows older than 90 days.
     *
     * @return array<string,mixed>
     */
    private function cleanupOldStats(): array
    {
        global $wpdb;

        $table  = $wpdb->prefix . 'cmc_campaign_stats';
        $cutoff = gmdate('Y-m-d', strtotime('-90 days'));

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE stat_date < %s", $cutoff)
        );

        return [
            'success' => true,
            'message' => sprintf(
                __('%d ردیف آمار قدیمی پاک شد.', 'campaignchi'),
                (int) $deleted
            ),
        ];
    }

    /**
     * Remove campaign_rules and campaign_products rows whose campaign no
     * longer exists in cmc_campaigns.
     *
     * @return array<string,mixed>
     */
    private function cleanupOrphanedRules(): array
    {
        global $wpdb;

        $rules    = $wpdb->prefix . 'cmc_campaign_rules';
        $products = $wpdb->prefix . 'cmc_campaign_products';
        $camps    = $wpdb->prefix . 'cmc_campaigns';

        $deletedRules = $wpdb->query(
            "DELETE r FROM {$rules} r LEFT JOIN {$camps} c ON r.campaign_id = c.id WHERE c.id IS NULL"
        );

        $deletedProducts = $wpdb->query(
            "DELETE p FROM {$products} p LEFT JOIN {$camps} c ON p.campaign_id = c.id WHERE c.id IS NULL"
        );

        $total = ((int) $deletedRules) + ((int) $deletedProducts);

        return [
            'success' => true,
            'message' => sprintf(
                __('%d ردیف یتیم پاک شد.', 'campaignchi'),
                $total
            ),
        ];
    }

    /**
     * Remove campaign_sales rows older than 365 days.
     *
     * @return array<string,mixed>
     */
    private function cleanupOldSales(): array
    {
        global $wpdb;

        $table  = $wpdb->prefix . 'cmc_campaign_sales';
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-365 days'));

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE sold_at < %s", $cutoff)
        );

        return [
            'success' => true,
            'message' => sprintf(
                __('%d ردیف لاگ فروش قدیمی پاک شد.', 'campaignchi'),
                (int) $deleted
            ),
        ];
    }

    /**
     * Delete all Campaignchi transients from the options table.
     *
     * @return array<string,mixed>
     */
    private function flushAllCaches(): array
    {
        global $wpdb;

        // Delete all transients that start with our prefix.
        $prefix  = '_transient_cmc_';
        $timeout = '_transient_timeout_cmc_';

        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%',
                $wpdb->esc_like($timeout) . '%'
            )
        );

        return [
            'success' => true,
            'message' => sprintf(
                __('%d مورد از کش سیستم پاک شد.', 'campaignchi'),
                $deleted
            ),
        ];
    }

    /**
     * Hard-delete all campaigns with status = 'ended', plus their products/rules/stats/sales.
     *
     * @return array<string,mixed>
     */
    private function deleteEndedCampaigns(): array
    {
        global $wpdb;

        $camps    = $wpdb->prefix . 'cmc_campaigns';
        $rules    = $wpdb->prefix . 'cmc_campaign_rules';
        $products = $wpdb->prefix . 'cmc_campaign_products';
        $stats    = $wpdb->prefix . 'cmc_campaign_stats';
        $sales    = $wpdb->prefix . 'cmc_campaign_sales';

        // Collect ids first.
        $ids = $wpdb->get_col("SELECT id FROM {$camps} WHERE status = 'ended'");

        if (empty($ids)) {
            return ['success' => true, 'message' => __('کمپین پایان‌یافته‌ای برای حذف وجود ندارد.', 'campaignchi')];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        foreach ([$rules, $products, $stats, $sales] as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE campaign_id IN ({$placeholders})", ...$ids));
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$camps} WHERE id IN ({$placeholders})", ...$ids));

        // Flush pricing cache — deleted campaigns were possibly still cached.
        CampaignResolver::flushCache();
        AnalyticsService::flushCampaignCandidatesCache();

        return [
            'success' => true,
            'message' => sprintf(
                __('%d کمپین پایان‌یافته به همراه تمام داده‌های مرتبط حذف شدند.', 'campaignchi'),
                count($ids)
            ),
        ];
    }

    /**
     * Full factory reset: drop all custom tables and delete all plugin options/transients.
     * After this the plugin behaves as if it was just installed.
     *
     * @return array<string,mixed>
     */
    private function factoryReset(): array
    {
        global $wpdb;

        // Drop all plugin tables.
        $tables = [
            'cmc_campaign_sales',
            'cmc_campaign_stats',
            'cmc_campaign_rules',
            'cmc_campaign_products',
            'cmc_campaigns',
            'cmc_sliders',
        ];

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }

        // Remove all plugin options.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('cmc_') . '%'
            )
        );

        // Remove all plugin transients.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_cmc_') . '%',
                $wpdb->esc_like('_transient_timeout_cmc_') . '%'
            )
        );

        // Unschedule cron.
        $timestamp = wp_next_scheduled('cmc_process_campaigns');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'cmc_process_campaigns');
        }

        return [
            'success' => true,
            'message' => __('پلاگین بازنشانی شد. در حال انتقال به پیشخوان وردپرس...', 'campaignchi'),
        ];
    }

    // -------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------

    /**
     * Reschedule the campaign-processing cron with a new interval.
     *
     * Uses the shared 'cmc_campaign_cron' schedule which PricingServiceProvider
     * registers on every load (reading the interval back from settings), so the
     * recurrence survives across requests instead of dying after one fire.
     */
    private function rescheduleCron(int $intervalMinutes): void
    {
        $hookName = 'cmc_process_campaigns';

        $existing = wp_next_scheduled($hookName);
        if ($existing) {
            wp_unschedule_event($existing, $hookName);
        }

        // The 'cmc_campaign_cron' schedule is registered by PricingServiceProvider
        // via the persistent 'cron_schedules' filter, which has already run this
        // request (plugins_loaded) and reflects the option we just saved.
        wp_schedule_event(time(), 'cmc_campaign_cron', $hookName);
    }

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
     * @param array<string,mixed> $data
     */
    private function json(array $data, int $status = 200): never
    {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($data);
        exit;
    }
}
