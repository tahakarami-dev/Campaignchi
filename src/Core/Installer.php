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
 * No UI or business logic allowed here.
 *
 * @package Msi\Campaignchi\Core
 */
class Installer
{
    /** @var string DB schema version option key */
    private const DB_VERSION_KEY = 'cmc_db_version';

    /** @var string Current schema version */
    private const DB_VERSION = '1.0.0';

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

        // Flush rewrite rules after menu registration
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
     * Create or upgrade custom database tables.
     * Uses dbDelta() for safe schema management.
     */
    private static function createTables(): void
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
            starts_at    DATETIME        NULL,
            ends_at      DATETIME        NULL,
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

        // Table: campaign_rules (group selection: category/tag/attribute)
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

        // Table: campaign_stats (aggregated analytics)
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

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($campaigns);
        dbDelta($campaign_products);
        dbDelta($campaign_rules);
        dbDelta($campaign_stats);

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
     * Schedule recurring background tasks.
     */
    private static function scheduleEvents(): void
    {
        if (!wp_next_scheduled('cmc_process_campaigns')) {
            wp_schedule_event(time(), 'hourly', 'cmc_process_campaigns');
        }
    }

    /**
     * Remove all plugin scheduled events on deactivation.
     */
    private static function clearScheduledEvents(): void
    {
        $events = ['cmc_process_campaigns'];

        foreach ($events as $event) {
            $timestamp = wp_next_scheduled($event);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $event);
            }
        }
    }
}