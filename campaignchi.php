<?php

/**
 * Plugin Name: کمپین‌چی
 * Plugin URI: https://www.rtl-theme.com/campaignchi-wordpress-plugin/
 * Description: حرفه‌ای‌ترین سیستم مدیریت کمپین‌های تخفیف و پیشنهاد شگفت‌انگیز برای ووکامرس با تمرکز روی تجربه کاربری و افزایش فروش
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: طاها کرمی
 * Author URI: https://www.rtl-theme.com/author/taha-karami
 * Text Domain: campaignchi
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * --------------------------------------------------------
 * Plugin Constants
 * --------------------------------------------------------
 */

define('CMC_VERSION', '1.0.0');
define('CMC_FILE', __FILE__);
define('CMC_BASENAME', plugin_basename(__FILE__));
define('CMC_PATH', plugin_dir_path(__FILE__));
define('CMC_URL', plugin_dir_url(__FILE__));
define('CMC_ASSETS_URL', CMC_URL . 'assets/');
define('CMC_INC_PATH', CMC_PATH . 'src/');

/**
 * --------------------------------------------------------
 * Composer Autoload
 * --------------------------------------------------------
 */

if (file_exists(CMC_PATH . 'vendor/autoload.php')) {
    require_once CMC_PATH . 'vendor/autoload.php';
}

/**
 * --------------------------------------------------------
 * Boot Plugin
 * --------------------------------------------------------
 */

if (!function_exists('campaignchi')) {

    /**
     * Main plugin instance
     */
    function campaignchi()
    {

    }
}

/**
 * --------------------------------------------------------
 * Initialize Plugin
 * --------------------------------------------------------
 */

add_action('plugins_loaded', function () {

    /**
     * Check WooCommerce
     */
    if (!class_exists('WooCommerce')) {

        add_action('admin_notices', function () {
?>
            <div class="notice notice-error">
                <p>
                    <?php esc_html_e(
                        'افزونه کمپین‌چی برای اجرا نیاز به ووکامرس دارد.',
                        'campaignchi'
                    ); ?>
                </p>
            </div>
<?php
        });

        return;
    }

    /**
     * Boot application
     */
    
});

/**
 * --------------------------------------------------------
 * Activation Hook
 * --------------------------------------------------------
 */

register_activation_hook(CMC_FILE, function () {

});

/**
 * --------------------------------------------------------
 * Deactivation Hook
 * --------------------------------------------------------
 */

register_deactivation_hook(CMC_FILE, function () {

});
