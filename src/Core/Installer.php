<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Core;

/**
 * Plugin Installer
 *
 * Runs on plugin activation:
 *  - Creates custom database tables
 *  - Sets default options
 *  - Handles version migration
 *
 * Runs on deactivation:
 *  - Clears scheduled events
 *
 * -----------------------------------------------------------------------
 * FIX (Section 10 — Issue A — Cron schedule registration):
 *
 * scheduleEvents() previously called wp_schedule_event() with
 * 'cmc_five_minutes' interval DIRECTLY, but the filter that registers
 * that interval on 'cron_schedules' is added inside
 * PricingServiceProvider::register() — which runs on 'plugins_loaded',
 * AFTER activation hooks. During activation the filter does not exist
 * yet, so WP silently rejects the custom interval and the event is never
 * scheduled.
 *
 * The fix: register the 'cmc_five_minutes' interval inline inside
 * scheduleEvents() using a one-shot add_filter() call, then immediately
 * call wp_schedule_event(). This guarantees the interval is always known
 * to WP at the exact moment the event is being scheduled, regardless of
 * which hook fired the scheduling call.
 * -----------------------------------------------------------------------
 *
 * @package Msi\Campaignchi\Core
 */
class Installer
{
    private const DB_VERSION_KEY = 'cmc_db_version';

    /**
     * Current schema version.
     * Bump this whenever the DB schema changes.
     */
    private const DB_VERSION = '1.2.0';

    // -------------------------------------------------------
    // Activation
    // -------------------------------------------------------

    /**
     * Run on plugin activation.
     * Called from register_activation_hook().
     */
    public static function activate(): void
    {
        self::createTables();
        self::setDefaultOptions();
        self::scheduleEvents();

        flush_rewrite_rules();
    }

    // -------------------------------------------------------
    // Deactivation
    // -------------------------------------------------------

    /**
     * Run on plugin deactivation.
     * Called from register_deactivation_hook().
     */
    public static function deactivate(): void
    {
        self::clearScheduledEvents();
        flush_rewrite_rules();
    }

    // -------------------------------------------------------
    // Database Tables
    // -------------------------------------------------------

    /**
     * Create or upgrade custom database tables using dbDelta().
     */
    public static function createTables(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        // Table: campaigns
        $campaigns = "CREATE TABLE {$wpdb->prefix}cmc_campaigns (
           id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
           title        VARCHAR(255)    NOT NULL,
           status       VARCHAR(20)     NOT NULL DEFAULT 'draft',
           type         VARCHAR(50)     NOT NULL DEFAULT 'flash_sale',
           discount     DECIMAL(10,2)   NOT NULL DEFAULT 0,
           discount_type VARCHAR(20)    NOT NULL DEFAULT 'percent',
           selection_mode VARCHAR(20)   NOT NULL DEFAULT 'manual',
           starts_at    DATETIME        NULL,
           ends_at      DATETIME        NULL,
           description  TEXT            NULL,
           created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
           updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           PRIMARY KEY (id),
           KEY status (status),
           KEY starts_at (starts_at),
           KEY ends_at (ends_at)
       ) $charset;";

        // Table: campaign_products (pivot)
        $campaign_products = "CREATE TABLE {$wpdb->prefix}cmc_campaign_products (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            product_id  BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_product (campaign_id, product_id),
            KEY product_id (product_id)
        ) $charset;";

        // Table: campaign_rules (group selection: category/tag/attribute/brand)
        $campaign_rules = "CREATE TABLE {$wpdb->prefix}cmc_campaign_rules (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            rule_type   VARCHAR(30)     NOT NULL,
            taxonomy    VARCHAR(100)    NOT NULL DEFAULT '',
            term_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY rule_type (rule_type)
        ) $charset;";

        // Table: campaign_stats (aggregated analytics — impressions)
        $campaign_stats = "CREATE TABLE {$wpdb->prefix}cmc_campaign_stats (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            stat_date   DATE            NOT NULL,
            impressions INT UNSIGNED    NOT NULL DEFAULT 0,
            clicks      INT UNSIGNED    NOT NULL DEFAULT 0,
            orders      INT UNSIGNED    NOT NULL DEFAULT 0,
            revenue     DECIMAL(12,2)   NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_date (campaign_id, stat_date),
            KEY campaign_id (campaign_id)
        ) $charset;";

        // Table: sliders (saved slider presets)
        $sliders = "CREATE TABLE {$wpdb->prefix}cmc_sliders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(190) NOT NULL,
            template VARCHAR(40) NOT NULL,
            campaign_id BIGINT UNSIGNED NULL,
            settings LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY template (template),
            KEY campaign_id (campaign_id)
        ) {$charset};";

        // Table: campaign_sales (accurate event log for Reports)
        $campaign_sales = "CREATE TABLE {$wpdb->prefix}cmc_campaign_sales (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id       BIGINT UNSIGNED NOT NULL,
            campaign_id    BIGINT UNSIGNED NOT NULL,
            product_id     BIGINT UNSIGNED NOT NULL,
            qty            INT UNSIGNED    NOT NULL DEFAULT 0,
            revenue        DECIMAL(12,2)   NOT NULL DEFAULT 0,
            customer_name  VARCHAR(255)    NOT NULL DEFAULT '',
            customer_email VARCHAR(255)    NOT NULL DEFAULT '',
            order_status   VARCHAR(30)     NOT NULL DEFAULT '',
            sold_at        DATETIME        NOT NULL,
            created_at     DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_product_campaign (order_id, product_id, campaign_id),
            KEY campaign_id (campaign_id),
            KEY order_status (order_status),
            KEY sold_at (sold_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($campaigns);
        dbDelta($campaign_products);
        dbDelta($campaign_rules);
        dbDelta($campaign_stats);
        dbDelta($sliders);
        dbDelta($campaign_sales);

        update_option(self::DB_VERSION_KEY, self::DB_VERSION);
    }

    // -------------------------------------------------------
    // Default Options
    // -------------------------------------------------------

    /**
     * Set plugin default options (only if not already set).
     */
    private static function setDefaultOptions(): void
    {
        $defaults = [
            'cmc_currency_symbol'  => '﷼',
            'cmc_countdown_style'  => 'modern',
            'cmc_badge_position'   => 'top-right',
            'cmc_enable_analytics' => true,
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    // -------------------------------------------------------
    // Scheduled Events
    // -------------------------------------------------------

    /**
     * Schedule the recurring campaign-processing cron event.
     *
     * Uses the canonical 'cmc_campaign_cron' schedule — the same key that
     * PricingServiceProvider registers and reads for interval changes.
     * This ensures the activation-time interval is consistent with the
     * settings-driven interval and that admin changes take effect without
     * needing to manually re-save settings.
     *
     * The interval is registered inline via a one-shot add_filter() call
     * immediately before wp_schedule_event() so WordPress can validate it
     * even though PricingServiceProvider has not booted yet (activation
     * runs before plugins_loaded).
     *
     * BUG FIX: Previously used 'cmc_five_minutes' which is a fixed 5-minute
     * interval. PricingServiceProvider::registerCron() would see the event
     * already scheduled and skip re-scheduling, permanently ignoring the
     * admin-configured cron_interval_minutes setting until the admin manually
     * re-saved Campaign settings.
     */
    private static function scheduleEvents(): void
    {
        // Remove any stale event (including old 'cmc_five_minutes' events) first.
        $existing = wp_next_scheduled('cmc_process_campaigns');
        if ($existing) {
            wp_unschedule_event($existing, 'cmc_process_campaigns');
        }

        // Read the admin-configured interval; fall back to 5 minutes if not set yet.
        $stored  = get_option('cmc_settings_campaign', []);
        $minutes = isset($stored['cron_interval_minutes'])
            ? (int) $stored['cron_interval_minutes']
            : 5;

        // Clamp to allowed values (same rule as PricingServiceProvider).
        if (!in_array($minutes, [5, 10, 15, 30], true)) {
            $minutes = 5;
        }

        // Register the canonical 'cmc_campaign_cron' schedule inline so it is
        // guaranteed to exist when wp_schedule_event() validates it.
        add_filter('cron_schedules', static function (array $schedules) use ($minutes): array {
            if (!isset($schedules['cmc_campaign_cron'])) {
                $schedules['cmc_campaign_cron'] = [
                    'interval' => $minutes * MINUTE_IN_SECONDS,
                    'display'  => sprintf('هر %d دقیقه (کمپین‌چی)', $minutes),
                ];
            }

            // Keep the legacy schedule so any old events that survived deactivation
            // can still fire and be replaced on the next boot.
            if (!isset($schedules['cmc_five_minutes'])) {
                $schedules['cmc_five_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => 'هر ۵ دقیقه (کمپین‌چی)',
                ];
            }

            return $schedules;
        });

        wp_schedule_event(time(), 'cmc_campaign_cron', 'cmc_process_campaigns');
    }

    /**
     * Remove all plugin scheduled events on deactivation.
     */
    private static function clearScheduledEvents(): void
    {
        foreach (['cmc_process_campaigns'] as $event) {
            $timestamp = wp_next_scheduled($event);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $event);
            }
        }
    }

    /**
     * Re-run table creation if the stored DB version is outdated.
     * Called from Application::boot() on every request (cheap option check).
     */
    public static function maybeUpgrade(): void
    {
        $stored = get_option(self::DB_VERSION_KEY);

        if ($stored === self::DB_VERSION) {
            return;
        }

        self::createTables();
        update_option(self::DB_VERSION_KEY, self::DB_VERSION);
    }
}