=== Campaignchi ===
Contributors: tahakarami
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Requires plugins: woocommerce
Stable tag: 1.0.0
License: Proprietary (commercial)
License URI: https://www.rtl-theme.com/campaignchi-wordpress-plugin/

Discount campaign and flash-offer management system for WooCommerce.

== Description ==

Campaignchi lets store owners build, schedule, and track discount campaigns
for WooCommerce: flash sales, "amazing offer" promotions, and product
sliders driven by those campaigns.

Core features:

* Flash sale and amazing-offer campaign types with automatic activation
  and expiry via WP-Cron.
* Product selection by manual pick, category, tag, attribute, or brand.
* Five built-in slider skins (Bold, Compact, Glass, Flux, Minimal),
  usable via shortcode, Elementor widget, or saved presets.
* Campaign analytics: impressions, revenue, orders, and top products,
  backed by a dedicated sales event log.
* Configurable cache TTLs and maintenance tools (cache flush, orphaned
  row cleanup, factory reset).

This plugin requires WooCommerce to be installed and active.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/campaignchi`, or
   install the plugin zip through the WordPress Plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Make sure WooCommerce is installed and active.
4. Open the "Campaignchi" menu in wp-admin to create your first campaign.

== Frequently Asked Questions ==

= Does this plugin work without WooCommerce? =

No. Campaignchi extends WooCommerce pricing and product data, so
WooCommerce must be installed and active.

= What happens to my data if I deactivate the plugin? =

Deactivating only clears the scheduled cron event; all campaigns,
sliders, and analytics data are preserved. Data is only removed if you
delete the plugin from the Plugins screen (see `uninstall.php`).

== Changelog ==

= 1.0.0 =
* Initial release.
