<?php

declare(strict_types=1);

// Fired only from WordPress's own uninstall routine — refuse any other entry point.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove every trace of Campaignchi: custom tables, options, transients,
 * and the recurring cron event. Runs once, when the plugin is deleted
 * from the Plugins screen (not on simple deactivation).
 */
function cmc_uninstall_cleanup(): void
{
    global $wpdb;

    $tables = [
        'cmc_campaign_sales',
        'cmc_campaign_stats',
        'cmc_campaign_rules',
        'cmc_campaign_products',
        'cmc_campaigns',
        'cmc_sliders',
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
    }

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('cmc_') . '%'
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_cmc_') . '%',
            $wpdb->esc_like('_transient_timeout_cmc_') . '%'
        )
    );

    $timestamp = wp_next_scheduled('cmc_process_campaigns');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'cmc_process_campaigns');
    }
    wp_clear_scheduled_hook('cmc_process_campaigns');
}

if (is_multisite()) {
    $siteIds = get_sites(['fields' => 'ids']);

    foreach ($siteIds as $siteId) {
        switch_to_blog((int) $siteId);
        cmc_uninstall_cleanup();
        restore_current_blog();
    }
} else {
    cmc_uninstall_cleanup();
}
