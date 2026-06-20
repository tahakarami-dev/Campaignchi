<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Controllers;

use Msi\Campaignchi\Admin\Pages\SettingsPage;
use Msi\Campaignchi\Campaign\Pricing\CampaignResolver;
use Msi\Campaignchi\Analytics\Services\AnalyticsService;

/**
 * Settings AJAX Controller
 *
 * Handles three admin-ajax actions:
 *
 *   cmc_save_settings_section  — persist one of the five settings groups.
 *   cmc_maintenance_action     — run a destructive/cleanup operation.
 *   cmc_test_webhook           — fire a test ping to the configured webhook URL.
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
        add_action('wp_ajax_cmc_test_webhook', [$this, 'testWebhook']);
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
            case 'access':
                $this->saveAccess($post);
                break;
            case 'integrations':
                $this->saveIntegrations($post);
                break;
            default:
                $this->json(['success' => false, 'message' => __('بخش نامعتبر است.', 'campaignchi')], 400);
        }
    }

    private function saveGeneral(array $post): void
    {
        $clean = [
            'price_format'        => in_array($post['price_format'] ?? '', ['woocommerce', 'custom'], true)
                                        ? $post['price_format'] : 'woocommerce',
            'custom_currency'     => sanitize_text_field($post['custom_currency'] ?? ''),
            'custom_currency_pos' => in_array($post['custom_currency_pos'] ?? '', ['left', 'right'], true)
                                        ? $post['custom_currency_pos'] : 'right',
            'number_separator'    => in_array($post['number_separator'] ?? '', ['comma', 'dot', 'space'], true)
                                        ? $post['number_separator'] : 'comma',
            'persian_digits'      => ($post['persian_digits'] ?? '0') === '1',
            'admin_bar_badge'     => ($post['admin_bar_badge'] ?? '0') === '1',
            'debug_mode'          => ($post['debug_mode'] ?? '0') === '1',
        ];

        update_option(SettingsPage::OPT_GENERAL, $clean);
        $this->json(['success' => true, 'message' => __('تنظیمات عمومی ذخیره شد.', 'campaignchi')]);
    }

    private function saveCampaign(array $post): void
    {
        $maxPct = max(1, min(100, absint($post['max_discount_percent'] ?? 90)));

        $clean = [
            'max_discount_percent'   => $maxPct,
            'max_discount_fixed'     => max(0, absint($post['max_discount_fixed'] ?? 0)),
            'overlap_strategy'       => in_array($post['overlap_strategy'] ?? '', ['priority', 'lowest_price', 'block'], true)
                                            ? $post['overlap_strategy'] : 'priority',
            'apply_on_sale_products' => ($post['apply_on_sale_products'] ?? '0') === '1',
            'exclude_outofstock'     => ($post['exclude_outofstock'] ?? '0') === '1',
            'cron_interval_minutes'  => in_array(absint($post['cron_interval_minutes'] ?? 5), [5, 10, 15, 30], true)
                                            ? absint($post['cron_interval_minutes']) : 5,
            'auto_expire_status'     => in_array($post['auto_expire_status'] ?? '', ['ended', 'draft'], true)
                                            ? $post['auto_expire_status'] : 'ended',
            'stack_with_coupons'     => ($post['stack_with_coupons'] ?? '0') === '1',
        ];

        update_option(SettingsPage::OPT_CAMPAIGN, $clean);

        // Reschedule cron with the new interval if it changed
        $this->rescheduleCron((int) $clean['cron_interval_minutes']);

        $this->json(['success' => true, 'message' => __('تنظیمات موتور کمپین ذخیره شد.', 'campaignchi')]);
    }

    private function savePerformance(array $post): void
    {
        $clean = [
            'pricing_cache_ttl'   => max(10,  min(3600, absint($post['pricing_cache_ttl']   ?? 300))),
            'analytics_cache_ttl' => max(10,  min(600,  absint($post['analytics_cache_ttl'] ?? 60))),
            'candidates_cache_ttl'=> max(60,  min(3600, absint($post['candidates_cache_ttl']?? 600))),
            'lazy_load_images'    => ($post['lazy_load_images']    ?? '0') === '1',
            'enable_query_cache'  => ($post['enable_query_cache']  ?? '0') === '1',
        ];

        update_option(SettingsPage::OPT_PERFORMANCE, $clean);

        // Flush pricing map so the new TTL is used on next rebuild
        CampaignResolver::flushCache();

        $this->json(['success' => true, 'message' => __('تنظیمات پرفورمنس ذخیره شد.', 'campaignchi')]);
    }

    private function saveAccess(array $post): void
    {
        // Validate the chosen capability exists on the current user to prevent
        // self-lockout — a user cannot set manage_capability to something
        // they themselves don't have.
        $manageCap = sanitize_text_field($post['manage_capability'] ?? 'manage_options');
        if (!current_user_can($manageCap)) {
            $this->json([
                'success' => false,
                'message' => __('نمی‌توانید یک capability را انتخاب کنید که خودتان ندارید.', 'campaignchi'),
            ], 403);
        }

        $clean = [
            'manage_capability' => $manageCap,
            'view_capability'   => sanitize_text_field($post['view_capability'] ?? 'edit_posts'),
            'enable_audit_log'  => ($post['enable_audit_log'] ?? '0') === '1',
            'audit_log_days'    => max(7, min(365, absint($post['audit_log_days'] ?? 30))),
        ];

        update_option(SettingsPage::OPT_ACCESS, $clean);
        $this->json(['success' => true, 'message' => __('تنظیمات دسترسی ذخیره شد.', 'campaignchi')]);
    }

    private function saveIntegrations(array $post): void
    {
        // Webhook events: only allow known values
        $allowedEvents  = ['campaign_created', 'campaign_updated', 'campaign_deleted', 'campaign_started', 'campaign_ended'];
        $rawEvents      = is_array($post['webhook_events'] ?? null) ? $post['webhook_events'] : [];
        $webhookEvents  = array_values(array_intersect(array_map('sanitize_key', $rawEvents), $allowedEvents));

        // Webhook URL: must be https
        $rawUrl      = esc_url_raw($post['webhook_url'] ?? '');
        $webhookUrl  = (filter_var($rawUrl, FILTER_VALIDATE_URL) && str_starts_with($rawUrl, 'https://')) ? $rawUrl : '';

        $clean = [
            'hpos_compatible'       => ($post['hpos_compatible']       ?? '0') === '1',
            'enable_rest_api'       => ($post['enable_rest_api']       ?? '0') === '1',
            'rest_api_key_required' => ($post['rest_api_key_required'] ?? '0') === '1',
            'webhook_url'           => $webhookUrl,
            'webhook_events'        => $webhookEvents,
        ];

        update_option(SettingsPage::OPT_INTEGRATIONS, $clean);
        $this->json(['success' => true, 'message' => __('تنظیمات یکپارچگی ذخیره شد.', 'campaignchi')]);
    }

    // -------------------------------------------------------
    // MAINTENANCE
    // -------------------------------------------------------

    public function maintenanceAction(): void
    {
        $this->verifyNonce();

        $actionType = sanitize_key($_POST['action_type'] ?? '');

        $result = match ($actionType) {
            'cleanup_old_stats'       => $this->cleanupOldStats(),
            'cleanup_orphaned_rules'  => $this->cleanupOrphanedRules(),
            'cleanup_old_sales'       => $this->cleanupOldSales(),
            'flush_all_caches'        => $this->flushAllCaches(),
            'delete_ended_campaigns'  => $this->deleteEndedCampaigns(),
            'factory_reset'           => $this->factoryReset(),
            default                   => ['success' => false, 'message' => __('عملیات نامعتبر.', 'campaignchi')],
        };

        $this->json($result);
    }

    /**
     * Remove analytics stat rows older than 90 days.
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
     */
    private function flushAllCaches(): array
    {
        global $wpdb;

        // Delete all transients that start with our prefix
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
     */
    private function deleteEndedCampaigns(): array
    {
        global $wpdb;

        $camps    = $wpdb->prefix . 'cmc_campaigns';
        $rules    = $wpdb->prefix . 'cmc_campaign_rules';
        $products = $wpdb->prefix . 'cmc_campaign_products';
        $stats    = $wpdb->prefix . 'cmc_campaign_stats';
        $sales    = $wpdb->prefix . 'cmc_campaign_sales';

        // Collect ids first
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

        // Flush pricing cache — deleted campaigns were possibly still cached
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
     */
    private function factoryReset(): array
    {
        global $wpdb;

        // Drop all plugin tables
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

        // Remove all plugin options
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('cmc_') . '%'
            )
        );

        // Remove all plugin transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_cmc_') . '%',
                $wpdb->esc_like('_transient_timeout_cmc_') . '%'
            )
        );

        // Unschedule cron
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
    // WEBHOOK TEST
    // -------------------------------------------------------

    public function testWebhook(): void
    {
        $this->verifyNonce();

        $integrations = SettingsPage::getIntegrations();
        $url          = $integrations['webhook_url'] ?? '';

        if (empty($url)) {
            $this->json(['success' => false, 'message' => __('آدرس Webhook تنظیم نشده است.', 'campaignchi')]);
        }

        $payload = wp_json_encode([
            'event'   => 'test',
            'source'  => 'campaignchi',
            'version' => CMC_VERSION,
            'site'    => home_url(),
            'time'    => current_time('c'),
        ]);

        $response = wp_remote_post($url, [
            'body'    => $payload,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-CMC-Event'  => 'test',
            ],
            'timeout'  => 10,
            'blocking' => true,
        ]);

        if (is_wp_error($response)) {
            $this->json(['success' => false, 'message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            $this->json([
                'success' => true,
                'message' => sprintf(__('درخواست تست با موفقیت ارسال شد (HTTP %d).', 'campaignchi'), $code),
            ]);
        }

        $this->json([
            'success' => false,
            'message' => sprintf(__('Webhook پاسخ خطا برگرداند (HTTP %d).', 'campaignchi'), $code),
        ]);
    }

    // -------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------

    /**
     * Reschedule the campaign-processing cron with a new interval.
     * Only fires if the interval actually changed.
     */
    private function rescheduleCron(int $intervalMinutes): void
    {
        $hookName   = 'cmc_process_campaigns';
        $scheduleId = 'cmc_' . $intervalMinutes . '_minutes';

        $existing = wp_next_scheduled($hookName);
        if ($existing) {
            wp_unschedule_event($existing, $hookName);
        }

        // Register the new schedule dynamically if not already present
        add_filter('cron_schedules', static function (array $schedules) use ($scheduleId, $intervalMinutes): array {
            $schedules[$scheduleId] = [
                'interval' => $intervalMinutes * MINUTE_IN_SECONDS,
                'display'  => sprintf('هر %d دقیقه (کمپین‌چی)', $intervalMinutes),
            ];
            return $schedules;
        });

        wp_schedule_event(time(), $scheduleId, $hookName);
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

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = 200): never
    {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($data);
        exit;
    }
}
