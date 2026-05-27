<?php

/**
 * Plugin Name: کمپین‌چی
 * Plugin URI: https://www.rtl-theme.com/campaignchi-wordpress-plugin/
 * Description: حرفه‌ای‌ترین سیستم مدیریت کمپین‌های تخفیف و پیشنهاد شگفت انگیز ووکامرس 
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: طاها کرمی 
 * Author URI: https://www.rtl-theme.com/author/taha-karami
 * Text Domain: campaignchi
 * Domain Path: /languages
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// -------------------------------------------------------
// Plugin Constants
// -------------------------------------------------------

define('CMC_VERSION',    '1.0.0');
define('CMC_FILE',       __FILE__);
define('CMC_BASENAME',   plugin_basename(__FILE__));
define('CMC_PATH',       plugin_dir_path(__FILE__));
define('CMC_URL',        plugin_dir_url(__FILE__));
define('CMC_ASSETS_URL', CMC_URL . 'assets/');
define('CMC_SRC_PATH',   CMC_PATH . 'src/');

// -------------------------------------------------------
// Composer Autoloader (PSR-4: Msi\Campaignchi\ → src/)
// -------------------------------------------------------

if (file_exists(CMC_PATH . 'vendor/autoload.php')) {
    require_once CMC_PATH . 'vendor/autoload.php';
}

// -------------------------------------------------------
// Boot on plugins_loaded (after all plugins are ready)
// -------------------------------------------------------

add_action('plugins_loaded', function (): void {

    // Require WooCommerce
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('کمپین‌چی نیاز به ووکامرس دارد.', 'campaignchi')
                . '</p></div>';
        });
        return;
    }

    // Boot the application kernel


}, 20); // Priority 20: after WooCommerce (priority 10)


// -------------------------------------------------------
// Activation Hook
// -------------------------------------------------------



// -------------------------------------------------------
// Deactivation Hook
// -------------------------------------------------------

