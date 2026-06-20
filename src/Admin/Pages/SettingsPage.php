<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

use Msi\Campaignchi\Core\Application;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Campaign\Pricing\CampaignResolver;
use Msi\Campaignchi\Analytics\Services\AnalyticsService;

/**
 * Settings Page
 *
 * Central control panel for the entire Campaignchi plugin.
 * Organized into five functional sections — each independently saveable
 * via AJAX — covering general behavior, campaign engine rules,
 * performance & caching, access control, and maintenance utilities.
 *
 * Option keys are all prefixed with `cmc_settings_` to distinguish them
 * from the appearance/slider options (cmc_slider_*) and the installer
 * defaults (cmc_currency_symbol, cmc_enable_analytics, ...) already
 * present in the DB. No option defined here overlaps with those.
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
class SettingsPage extends AbstractPage
{
    // -------------------------------------------------------
    // Option key constants — single source of truth
    // -------------------------------------------------------

    // General
    public const OPT_GENERAL       = 'cmc_settings_general';

    // Campaign engine
    public const OPT_CAMPAIGN      = 'cmc_settings_campaign';

    // Performance / caching
    public const OPT_PERFORMANCE   = 'cmc_settings_performance';

    // Access control
    public const OPT_ACCESS        = 'cmc_settings_access';

    // Integrations
    public const OPT_INTEGRATIONS  = 'cmc_settings_integrations';

    // -------------------------------------------------------
    // Hardcoded defaults — parallel to SliderSettingsService
    // -------------------------------------------------------

    private static function defaultGeneral(): array
    {
        return [
            'price_format'        => 'woocommerce', // 'woocommerce' | 'custom'
            'custom_currency'     => '',
            'custom_currency_pos' => 'right',       // 'left' | 'right'
            'number_separator'    => 'comma',        // 'comma' | 'dot' | 'space'
            'persian_digits'      => true,
            'admin_bar_badge'     => true,           // show live-campaign count in WP admin bar
            'debug_mode'          => false,
        ];
    }

    private static function defaultCampaign(): array
    {
        return [
            'max_discount_percent'    => 90,         // hard ceiling for % discounts (1-100)
            'max_discount_fixed'      => 0,          // hard ceiling for fixed discounts (0 = unlimited)
            'overlap_strategy'        => 'priority', // 'priority' | 'lowest_price' | 'block'
            'apply_on_sale_products'  => true,       // whether to discount already-on-sale products
            'exclude_outofstock'      => false,      // skip out-of-stock products in sliders
            'cron_interval_minutes'   => 5,          // how often auto-transition cron fires (5|10|15|30)
            'auto_expire_status'      => 'ended',    // status applied when a flash sale expires
            'stack_with_coupons'      => true,       // whether campaign price stacks with WC coupons
        ];
    }

    private static function defaultPerformance(): array
    {
        return [
            'pricing_cache_ttl'    => 300,    // seconds — max TTL for the pricing map transient
            'analytics_cache_ttl'  => 60,     // seconds — TTL for today's dashboard analytics cache
            'candidates_cache_ttl' => 600,    // seconds — TTL for campaign-candidates cache
            'lazy_load_images'     => true,   // add loading="lazy" to slider product images
            'enable_query_cache'   => true,   // use transients for heavy DB reads
        ];
    }

    private static function defaultAccess(): array
    {
        return [
            'manage_capability'  => 'manage_options',  // who can fully manage campaigns
            'view_capability'    => 'edit_posts',      // who can view the dashboard (read-only)
            'enable_audit_log'   => false,             // log create/update/delete actions
            'audit_log_days'     => 30,                // how long to keep audit entries
        ];
    }

    private static function defaultIntegrations(): array
    {
        return [
            'hpos_compatible'       => true,   // use WC HPOS order tables when available
            'enable_rest_api'       => false,  // expose /wp-json/campaignchi/v1/ endpoints
            'rest_api_key_required' => true,   // require X-CMC-Key header on REST calls
            'webhook_url'           => '',     // POST campaign events here (empty = disabled)
            'webhook_events'        => [],     // which events to send: campaign_created, campaign_ended, ...
        ];
    }

    // -------------------------------------------------------
    // Public accessors — called by other parts of the system
    // -------------------------------------------------------

    public static function getGeneral(): array
    {
        $stored = get_option(self::OPT_GENERAL, []);
        return array_merge(self::defaultGeneral(), is_array($stored) ? $stored : []);
    }

    public static function getCampaign(): array
    {
        $stored = get_option(self::OPT_CAMPAIGN, []);
        return array_merge(self::defaultCampaign(), is_array($stored) ? $stored : []);
    }

    public static function getPerformance(): array
    {
        $stored = get_option(self::OPT_PERFORMANCE, []);
        return array_merge(self::defaultPerformance(), is_array($stored) ? $stored : []);
    }

    public static function getAccess(): array
    {
        $stored = get_option(self::OPT_ACCESS, []);
        return array_merge(self::defaultAccess(), is_array($stored) ? $stored : []);
    }

    public static function getIntegrations(): array
    {
        $stored = get_option(self::OPT_INTEGRATIONS, []);
        return array_merge(self::defaultIntegrations(), is_array($stored) ? $stored : []);
    }

    // -------------------------------------------------------
    // AbstractPage implementation
    // -------------------------------------------------------

    public function title(): string
    {
        return __('تنظیمات', 'campaignchi');
    }

    public function render(): void
    {
        $general      = self::getGeneral();
        $campaign     = self::getCampaign();
        $performance  = self::getPerformance();
        $access       = self::getAccess();
        $integrations = self::getIntegrations();

        // System status data for the maintenance card
        $dbVersion     = get_option('cmc_db_version', '—');
        $pricingCache  = (bool) get_transient('cmc_pricing_map_v1');
        $candidatesCache = (bool) get_transient('cmc_campaign_candidates_v1');
        $totalCampaigns = 0;
        try {
            $repo = Application::getInstance()->make(CampaignRepository::class);
            $totalCampaigns = $repo->paginate(['per_page' => 1])['total'];
        } catch (\Throwable) {}
?>

        <!-- ======================================================
             PAGE HEADER
        ====================================================== -->
        <div class="cmc-row cmc-row--between cmc-mb-5">
            <div>
                <h2 style="font-size:var(--cmc-font-size-xl);font-weight:700;color:var(--cmc-text-heading);margin:0">
                    <?php esc_html_e('تنظیمات', 'campaignchi'); ?>
                </h2>
                <p style="color:var(--cmc-text-muted);font-size:var(--cmc-font-size-sm);margin:4px 0 0">
                    <?php esc_html_e('کنترل کامل رفتار، عملکرد و یکپارچگی‌های پلاگین. هر بخش به‌صورت مستقل ذخیره می‌شود.', 'campaignchi'); ?>
                </p>
            </div>
        </div>

        <!-- Toast container for AJAX feedback -->
        <div id="cmc-settings-feedback"></div>

        <!-- ======================================================
             TAB NAV
        ====================================================== -->
        <div class="cmc-tabs cmc-mb-5" id="cmc-settings-tabs">
            <div class="cmc-tab is-active" data-target="cmc-tab-general">
                <i class="ti ti-adjustments-horizontal"></i>
                <?php esc_html_e('عمومی', 'campaignchi'); ?>
            </div>
            <div class="cmc-tab" data-target="cmc-tab-campaign">
                <i class="ti ti-bolt"></i>
                <?php esc_html_e('موتور کمپین', 'campaignchi'); ?>
            </div>
            <div class="cmc-tab" data-target="cmc-tab-performance">
                <i class="ti ti-rocket"></i>
                <?php esc_html_e('پرفورمنس', 'campaignchi'); ?>
            </div>
            <div class="cmc-tab" data-target="cmc-tab-access">
                <i class="ti ti-shield-lock"></i>
                <?php esc_html_e('دسترسی', 'campaignchi'); ?>
            </div>
            <div class="cmc-tab" data-target="cmc-tab-integrations">
                <i class="ti ti-plug-connected"></i>
                <?php esc_html_e('یکپارچگی‌ها', 'campaignchi'); ?>
            </div>
            <div class="cmc-tab" data-target="cmc-tab-maintenance">
                <i class="ti ti-tool"></i>
                <?php esc_html_e('نگهداری', 'campaignchi'); ?>
            </div>
        </div>

        <!-- ======================================================
             TAB: GENERAL
        ====================================================== -->
        <div class="cmc-tab-panel" id="cmc-tab-general">
            <div class="cmc-stack cmc-stack--md">

                <!-- Price & Currency -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('قیمت و ارز', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('نحوه‌ی نمایش قیمت‌ها در اسلایدر و بج‌های تخفیف', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <div class="cmc-grid cmc-grid--3">

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-price-format"><?php esc_html_e('فرمت قیمت', 'campaignchi'); ?></label>
                            <select id="cmc-s-price-format" class="cmc-select" name="price_format">
                                <option value="woocommerce" <?php selected($general['price_format'], 'woocommerce'); ?>><?php esc_html_e('پیروی از تنظیمات ووکامرس', 'campaignchi'); ?></option>
                                <option value="custom" <?php selected($general['price_format'], 'custom'); ?>><?php esc_html_e('سفارشی', 'campaignchi'); ?></option>
                            </select>
                            <span class="cmc-form-hint"><?php esc_html_e('در حالت سفارشی، گزینه‌های زیر فعال می‌شوند', 'campaignchi'); ?></span>
                        </div>

                        <div class="cmc-form-group" id="cmc-s-currency-wrap">
                            <label class="cmc-label" for="cmc-s-custom-currency"><?php esc_html_e('نماد ارز سفارشی', 'campaignchi'); ?></label>
                            <input type="text" id="cmc-s-custom-currency" name="custom_currency" class="cmc-input"
                                value="<?php echo esc_attr($general['custom_currency']); ?>"
                                placeholder="<?php esc_attr_e('مثلاً: تومان یا ﷼', 'campaignchi'); ?>"
                                maxlength="10">
                        </div>

                        <div class="cmc-form-group" id="cmc-s-currency-pos-wrap">
                            <label class="cmc-label" for="cmc-s-currency-pos"><?php esc_html_e('موقعیت ارز', 'campaignchi'); ?></label>
                            <select id="cmc-s-currency-pos" name="custom_currency_pos" class="cmc-select">
                                <option value="right" <?php selected($general['custom_currency_pos'], 'right'); ?>><?php esc_html_e('راست (بعد از عدد)', 'campaignchi'); ?></option>
                                <option value="left"  <?php selected($general['custom_currency_pos'], 'left'); ?>><?php esc_html_e('چپ (قبل از عدد)', 'campaignchi'); ?></option>
                            </select>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-number-sep"><?php esc_html_e('جداکننده‌ی هزارگان', 'campaignchi'); ?></label>
                            <select id="cmc-s-number-sep" name="number_separator" class="cmc-select">
                                <option value="comma" <?php selected($general['number_separator'], 'comma'); ?>><?php esc_html_e('ویرگول (۱،۰۰۰)', 'campaignchi'); ?></option>
                                <option value="dot"   <?php selected($general['number_separator'], 'dot'); ?>><?php esc_html_e('نقطه (۱.۰۰۰)', 'campaignchi'); ?></option>
                                <option value="space" <?php selected($general['number_separator'], 'space'); ?>><?php esc_html_e('فاصله (۱ ۰۰۰)', 'campaignchi'); ?></option>
                            </select>
                        </div>

                    </div>

                    <hr class="cmc-divider">

                    <div class="cmc-grid cmc-grid--3">
                        <?php $this->renderToggle(
                            'cmc-s-persian-digits', 'persian_digits',
                            __('اعداد فارسی', 'campaignchi'),
                            __('نمایش اعداد به شکل فارسی (۱۲۳ به‌جای 123)', 'campaignchi'),
                            (bool) $general['persian_digits']
                        ); ?>

                        <?php $this->renderToggle(
                            'cmc-s-adminbar-badge', 'admin_bar_badge',
                            __('نشانگر نوار ادمین', 'campaignchi'),
                            __('نمایش تعداد کمپین فعال در نوار مدیریت وردپرس', 'campaignchi'),
                            (bool) $general['admin_bar_badge']
                        ); ?>

                        <?php $this->renderToggle(
                            'cmc-s-debug-mode', 'debug_mode',
                            __('حالت دیباگ', 'campaignchi'),
                            __('ثبت لاگ‌های تشخیصی در error_log وردپرس (فقط برای توسعه)', 'campaignchi'),
                            (bool) $general['debug_mode'],
                            'warning'
                        ); ?>
                    </div>
                </div>

                <?php $this->renderSaveButton('general', __('ذخیره تنظیمات عمومی', 'campaignchi')); ?>

            </div>
        </div><!-- /#cmc-tab-general -->

        <!-- ======================================================
             TAB: CAMPAIGN ENGINE
        ====================================================== -->
        <div class="cmc-tab-panel" id="cmc-tab-campaign" hidden>
            <div class="cmc-stack cmc-stack--md">

                <!-- Discount Rules -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('قوانین تخفیف', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('کنترل سقف تخفیف و نحوه‌ی تعامل با سایر تخفیف‌ها', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <div class="cmc-grid cmc-grid--3">

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-max-pct"><?php esc_html_e('حداکثر تخفیف درصدی', 'campaignchi'); ?></label>
                            <div class="cmc-input-wrap">
                                <i class="ti ti-percentage cmc-input-wrap__icon"></i>
                                <input type="number" id="cmc-s-max-pct" name="max_discount_percent" class="cmc-input"
                                    min="1" max="100" value="<?php echo esc_attr((string) $campaign['max_discount_percent']); ?>">
                            </div>
                            <span class="cmc-form-hint"><?php esc_html_e('ذخیره‌سازی بیش از این مقدار مجاز نیست (۱ تا ۱۰۰)', 'campaignchi'); ?></span>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-max-fixed"><?php esc_html_e('حداکثر تخفیف ثابت', 'campaignchi'); ?></label>
                            <div class="cmc-input-wrap">
                                <i class="ti ti-cash cmc-input-wrap__icon"></i>
                                <input type="number" id="cmc-s-max-fixed" name="max_discount_fixed" class="cmc-input"
                                    min="0" step="1000" value="<?php echo esc_attr((string) $campaign['max_discount_fixed']); ?>"
                                    placeholder="<?php esc_attr_e('۰ = بدون محدودیت', 'campaignchi'); ?>">
                            </div>
                            <span class="cmc-form-hint"><?php esc_html_e('صفر یعنی بدون سقف برای تخفیف ثابت', 'campaignchi'); ?></span>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-overlap"><?php esc_html_e('استراتژی تداخل کمپین‌ها', 'campaignchi'); ?></label>
                            <select id="cmc-s-overlap" name="overlap_strategy" class="cmc-select">
                                <option value="priority"    <?php selected($campaign['overlap_strategy'], 'priority'); ?>><?php esc_html_e('اولویت — بالاترین اولویت برنده می‌شود', 'campaignchi'); ?></option>
                                <option value="lowest_price" <?php selected($campaign['overlap_strategy'], 'lowest_price'); ?>><?php esc_html_e('پایین‌ترین قیمت — بهترین قیمت برای مشتری', 'campaignchi'); ?></option>
                                <option value="block"       <?php selected($campaign['overlap_strategy'], 'block'); ?>><?php esc_html_e('مسدودسازی — هر محصول فقط در یک کمپین', 'campaignchi'); ?></option>
                            </select>
                            <span class="cmc-form-hint"><?php esc_html_e('وقتی یک محصول در چند کمپین هم‌زمان باشد', 'campaignchi'); ?></span>
                        </div>

                    </div>

                    <hr class="cmc-divider">

                    <div class="cmc-grid cmc-grid--3">
                        <?php $this->renderToggle(
                            'cmc-s-apply-sale', 'apply_on_sale_products',
                            __('اعمال روی محصولات حراجی', 'campaignchi'),
                            __('تخفیف کمپین به محصولاتی که قبلاً روی آن‌ها تخفیف ثبت شده هم اعمال شود', 'campaignchi'),
                            (bool) $campaign['apply_on_sale_products']
                        ); ?>

                        <?php $this->renderToggle(
                            'cmc-s-exclude-oos', 'exclude_outofstock',
                            __('حذف محصولات ناموجود', 'campaignchi'),
                            __('محصولات out-of-stock از اسلایدر کمپین پنهان شوند', 'campaignchi'),
                            (bool) $campaign['exclude_outofstock']
                        ); ?>

                        <?php $this->renderToggle(
                            'cmc-s-stack-coupons', 'stack_with_coupons',
                            __('ترکیب با کوپن‌های ووکامرس', 'campaignchi'),
                            __('قیمت کمپین با کوپن‌های ووکامرس هم‌زمان اعمال شود', 'campaignchi'),
                            (bool) $campaign['stack_with_coupons']
                        ); ?>
                    </div>
                </div>

                <!-- Cron & Auto-Transition -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('زمان‌بندی خودکار', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('کنترل تناوب تغییر وضعیت کمپین‌ها و رفتار انقضا', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <div class="cmc-grid cmc-grid--3">

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-cron-interval"><?php esc_html_e('تناوب کران (دقیقه)', 'campaignchi'); ?></label>
                            <select id="cmc-s-cron-interval" name="cron_interval_minutes" class="cmc-select">
                                <?php foreach ([5, 10, 15, 30] as $min): ?>
                                    <option value="<?php echo $min; ?>" <?php selected($campaign['cron_interval_minutes'], $min); ?>>
                                        <?php echo esc_html(sprintf(__('هر %d دقیقه', 'campaignchi'), $min)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="cmc-form-hint"><?php esc_html_e('حداقل زمان بین بررسی شروع/پایان فلش‌سیل‌ها', 'campaignchi'); ?></span>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-expire-status"><?php esc_html_e('وضعیت پس از انقضا', 'campaignchi'); ?></label>
                            <select id="cmc-s-expire-status" name="auto_expire_status" class="cmc-select">
                                <option value="ended"  <?php selected($campaign['auto_expire_status'], 'ended'); ?>><?php esc_html_e('پایان‌یافته', 'campaignchi'); ?></option>
                                <option value="draft"  <?php selected($campaign['auto_expire_status'], 'draft'); ?>><?php esc_html_e('پیش‌نویس', 'campaignchi'); ?></option>
                            </select>
                            <span class="cmc-form-hint"><?php esc_html_e('وضعیتی که فلش‌سیل پس از پایان تاریخش می‌گیرد', 'campaignchi'); ?></span>
                        </div>

                    </div>
                </div>

                <?php $this->renderSaveButton('campaign', __('ذخیره تنظیمات کمپین', 'campaignchi')); ?>

            </div>
        </div><!-- /#cmc-tab-campaign -->

        <!-- ======================================================
             TAB: PERFORMANCE
        ====================================================== -->
        <div class="cmc-tab-panel" id="cmc-tab-performance" hidden>
            <div class="cmc-stack cmc-stack--md">

                <!-- Cache Status -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('وضعیت کش', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('وضعیت لحظه‌ای transient‌های اصلی سیستم', 'campaignchi'); ?></div>
                        </div>
                        <button type="button" id="cmc-s-flush-all" class="cmc-btn cmc-btn--secondary cmc-btn--sm">
                            <i class="ti ti-refresh"></i>
                            <?php esc_html_e('پاکسازی همه کش‌ها', 'campaignchi'); ?>
                        </button>
                    </div>

                    <div class="cmc-grid cmc-grid--3">
                        <?php $this->renderCacheStatusBadge(
                            __('نقشه قیمت‌گذاری', 'campaignchi'),
                            'cmc_pricing_map_v1',
                            __('pricing map', 'campaignchi')
                        ); ?>
                        <?php $this->renderCacheStatusBadge(
                            __('لیست کمپین‌های فعال', 'campaignchi'),
                            'cmc_campaign_candidates_v1',
                            __('campaign candidates', 'campaignchi')
                        ); ?>
                        <?php $this->renderCacheStatusBadge(
                            __('آنالیتیکس امروز', 'campaignchi'),
                            'cmc_daily_campaign_data_v2_' . date('Y-m-d'),
                            __('today analytics', 'campaignchi')
                        ); ?>
                    </div>
                </div>

                <!-- Cache TTL Config -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('تنظیمات TTL', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('مدت اعتبار کش‌های مختلف (ثانیه). کمتر = تازه‌تر ولی کندتر. بیشتر = سریع‌تر ولی ممکنه کمی کهنه باشه.', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <div class="cmc-grid cmc-grid--3">

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-pricing-ttl"><?php esc_html_e('کش قیمت‌گذاری (ثانیه)', 'campaignchi'); ?></label>
                            <input type="number" id="cmc-s-pricing-ttl" name="pricing_cache_ttl" class="cmc-input"
                                min="10" max="3600" step="10"
                                value="<?php echo esc_attr((string) $performance['pricing_cache_ttl']); ?>">
                            <span class="cmc-form-hint"><?php esc_html_e('نقشه‌ی product→campaign. پیشنهاد: ۳۰۰ ثانیه', 'campaignchi'); ?></span>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-analytics-ttl"><?php esc_html_e('کش آنالیتیکس (ثانیه)', 'campaignchi'); ?></label>
                            <input type="number" id="cmc-s-analytics-ttl" name="analytics_cache_ttl" class="cmc-input"
                                min="10" max="600" step="10"
                                value="<?php echo esc_attr((string) $performance['analytics_cache_ttl']); ?>">
                            <span class="cmc-form-hint"><?php esc_html_e('داده‌های فروش امروز در داشبورد. پیشنهاد: ۶۰ ثانیه', 'campaignchi'); ?></span>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-candidates-ttl"><?php esc_html_e('کش لیست کمپین‌ها (ثانیه)', 'campaignchi'); ?></label>
                            <input type="number" id="cmc-s-candidates-ttl" name="candidates_cache_ttl" class="cmc-input"
                                min="60" max="3600" step="60"
                                value="<?php echo esc_attr((string) $performance['candidates_cache_ttl']); ?>">
                            <span class="cmc-form-hint"><?php esc_html_e('لیست resolve-شده‌ی محصولات هر کمپین. پیشنهاد: ۶۰۰ ثانیه', 'campaignchi'); ?></span>
                        </div>

                    </div>

                    <hr class="cmc-divider">

                    <div class="cmc-grid cmc-grid--3">
                        <?php $this->renderToggle(
                            'cmc-s-lazy-images', 'lazy_load_images',
                            __('Lazy Load تصاویر', 'campaignchi'),
                            __('اضافه کردن loading="lazy" به تصاویر محصولات اسلایدر برای بارگذاری سریع‌تر صفحه', 'campaignchi'),
                            (bool) $performance['lazy_load_images']
                        ); ?>

                        <?php $this->renderToggle(
                            'cmc-s-query-cache', 'enable_query_cache',
                            __('کش کوئری‌های سنگین', 'campaignchi'),
                            __('استفاده از transient برای ذخیره نتایج کوئری‌های مرتبط با آنالیتیکس (توصیه می‌شود)', 'campaignchi'),
                            (bool) $performance['enable_query_cache']
                        ); ?>
                    </div>
                </div>

                <?php $this->renderSaveButton('performance', __('ذخیره تنظیمات پرفورمنس', 'campaignchi')); ?>

            </div>
        </div><!-- /#cmc-tab-performance -->

        <!-- ======================================================
             TAB: ACCESS CONTROL
        ====================================================== -->
        <div class="cmc-tab-panel" id="cmc-tab-access" hidden>
            <div class="cmc-stack cmc-stack--md">

                <div class="cmc-alert cmc-alert--warning cmc-mb-4">
                    <i class="ti ti-alert-triangle cmc-alert__icon"></i>
                    <div class="cmc-alert__body">
                        <div class="cmc-alert__title"><?php esc_html_e('احتیاط', 'campaignchi'); ?></div>
                        <?php esc_html_e('تغییر capability مدیریت به یک مقدار اشتباه ممکنه دسترسی شما را قطع کند. قبل از ذخیره مطمئن بشید که capability انتخاب‌شده به کاربر فعلی شما تعلق دارد.', 'campaignchi'); ?>
                    </div>
                </div>

                <!-- Role & Capability -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('سطح دسترسی', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('کدام نقش وردپرس اجازه‌ی استفاده از کمپین‌چی را دارد', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <?php
                    // Collect all capabilities from all registered roles for the dropdowns
                    $allCaps = $this->getAvailableCapabilities();
                    ?>

                    <div class="cmc-grid cmc-grid--2">

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-manage-cap"><?php esc_html_e('سطح مدیریت کامل', 'campaignchi'); ?></label>
                            <select id="cmc-s-manage-cap" name="manage_capability" class="cmc-select">
                                <?php foreach ($allCaps as $cap): ?>
                                    <option value="<?php echo esc_attr($cap); ?>" <?php selected($access['manage_capability'], $cap); ?>>
                                        <?php echo esc_html($cap); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="cmc-form-hint"><?php esc_html_e('ایجاد، ویرایش، حذف کمپین‌ها و دسترسی به تنظیمات', 'campaignchi'); ?></span>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-view-cap"><?php esc_html_e('سطح دسترسی نمایشی', 'campaignchi'); ?></label>
                            <select id="cmc-s-view-cap" name="view_capability" class="cmc-select">
                                <?php foreach ($allCaps as $cap): ?>
                                    <option value="<?php echo esc_attr($cap); ?>" <?php selected($access['view_capability'], $cap); ?>>
                                        <?php echo esc_html($cap); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="cmc-form-hint"><?php esc_html_e('مشاهده داشبورد و گزارش‌ها بدون امکان ویرایش', 'campaignchi'); ?></span>
                        </div>

                    </div>
                </div>

                <!-- Audit Log -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('لاگ عملیات (Audit Log)', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('ثبت تمام تغییرات کمپین‌ها به همراه کاربر، تاریخ و جزئیات عملیات', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <div class="cmc-grid cmc-grid--2">

                        <?php $this->renderToggle(
                            'cmc-s-audit-log', 'enable_audit_log',
                            __('فعال‌سازی لاگ عملیات', 'campaignchi'),
                            __('هر ایجاد، ویرایش یا حذف کمپین با اطلاعات کاربر ثبت می‌شود', 'campaignchi'),
                            (bool) $access['enable_audit_log']
                        ); ?>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-audit-days"><?php esc_html_e('نگهداری لاگ (روز)', 'campaignchi'); ?></label>
                            <input type="number" id="cmc-s-audit-days" name="audit_log_days" class="cmc-input"
                                min="7" max="365"
                                value="<?php echo esc_attr((string) $access['audit_log_days']); ?>">
                            <span class="cmc-form-hint"><?php esc_html_e('لاگ‌های قدیمی‌تر از این تعداد روز به‌صورت خودکار پاک می‌شوند', 'campaignchi'); ?></span>
                        </div>

                    </div>
                </div>

                <?php $this->renderSaveButton('access', __('ذخیره تنظیمات دسترسی', 'campaignchi')); ?>

            </div>
        </div><!-- /#cmc-tab-access -->

        <!-- ======================================================
             TAB: INTEGRATIONS
        ====================================================== -->
        <div class="cmc-tab-panel" id="cmc-tab-integrations" hidden>
            <div class="cmc-stack cmc-stack--md">

                <!-- WooCommerce -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('ووکامرس', 'campaignchi'); ?></div>
                        </div>
                        <span class="cmc-badge cmc-badge--active"><span class="cmc-badge__dot"></span> <?php esc_html_e('متصل', 'campaignchi'); ?></span>
                    </div>

                    <?php $this->renderToggle(
                        'cmc-s-hpos', 'hpos_compatible',
                        __('سازگاری با HPOS', 'campaignchi'),
                        __('استفاده از جداول سفارش‌های جدید ووکامرس (High-Performance Order Storage) در صورت فعال بودن', 'campaignchi'),
                        (bool) $integrations['hpos_compatible']
                    ); ?>
                </div>

                <!-- REST API -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('REST API', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('اندپوینت‌های /wp-json/campaignchi/v1/ برای دسترسی خارجی', 'campaignchi'); ?></div>
                        </div>
                        <?php if ($integrations['enable_rest_api']): ?>
                            <span class="cmc-badge cmc-badge--active"><span class="cmc-badge__dot"></span> <?php esc_html_e('فعال', 'campaignchi'); ?></span>
                        <?php else: ?>
                            <span class="cmc-badge cmc-badge--draft"><?php esc_html_e('غیرفعال', 'campaignchi'); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="cmc-grid cmc-grid--2">
                        <?php $this->renderToggle(
                            'cmc-s-rest-api', 'enable_rest_api',
                            __('فعال‌سازی REST API', 'campaignchi'),
                            __('اطلاعات کمپین‌های فعال از طریق REST API در دسترس قرار می‌گیرد', 'campaignchi'),
                            (bool) $integrations['enable_rest_api']
                        ); ?>

                        <?php $this->renderToggle(
                            'cmc-s-rest-key', 'rest_api_key_required',
                            __('نیاز به API Key', 'campaignchi'),
                            __('هدر X-CMC-Key برای همه‌ی درخواست‌های REST الزامی باشد', 'campaignchi'),
                            (bool) $integrations['rest_api_key_required']
                        ); ?>
                    </div>

                    <?php if ($integrations['enable_rest_api']): ?>
                        <hr class="cmc-divider">
                        <div class="cmc-alert cmc-alert--info">
                            <i class="ti ti-info-circle cmc-alert__icon"></i>
                            <div class="cmc-alert__body">
                                <div class="cmc-alert__title"><?php esc_html_e('اندپوینت‌های فعال', 'campaignchi'); ?></div>
                                <code style="font-size:11px;direction:ltr;display:block;margin-top:4px">
                                    GET <?php echo esc_html(rest_url('campaignchi/v1/campaigns')); ?><br>
                                    GET <?php echo esc_html(rest_url('campaignchi/v1/campaigns/{id}')); ?><br>
                                    GET <?php echo esc_html(rest_url('campaignchi/v1/products/{id}/discount')); ?>
                                </code>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Webhook -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('Webhook رویدادها', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('ارسال اعلان HTTP به یک آدرس خارجی هنگام وقوع رویدادهای کلیدی', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <div class="cmc-form-group cmc-mb-4">
                        <label class="cmc-label" for="cmc-s-webhook-url"><?php esc_html_e('آدرس Webhook', 'campaignchi'); ?></label>
                        <div class="cmc-input-wrap">
                            <i class="ti ti-link cmc-input-wrap__icon"></i>
                            <input type="url" id="cmc-s-webhook-url" name="webhook_url" class="cmc-input"
                                value="<?php echo esc_attr($integrations['webhook_url']); ?>"
                                placeholder="https://example.com/webhook/campaignchi"
                                style="direction:ltr;text-align:left">
                        </div>
                        <span class="cmc-form-hint"><?php esc_html_e('خالی = Webhook غیرفعال. کمپین‌چی یک POST request با payload JSON ارسال می‌کند.', 'campaignchi'); ?></span>
                    </div>

                    <div class="cmc-form-group">
                        <label class="cmc-label"><?php esc_html_e('رویدادهای ارسالی', 'campaignchi'); ?></label>
                        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:6px">
                            <?php
                            $webhookEvents = [
                                'campaign_created' => __('ایجاد کمپین', 'campaignchi'),
                                'campaign_updated' => __('ویرایش کمپین', 'campaignchi'),
                                'campaign_deleted' => __('حذف کمپین', 'campaignchi'),
                                'campaign_started' => __('شروع فلش‌سیل', 'campaignchi'),
                                'campaign_ended'   => __('پایان فلش‌سیل', 'campaignchi'),
                            ];
                            foreach ($webhookEvents as $eventKey => $eventLabel):
                                $checked = in_array($eventKey, (array) $integrations['webhook_events'], true);
                            ?>
                                <label class="cmc-checkbox">
                                    <input type="checkbox" class="cmc-checkbox__input cmc-s-webhook-event"
                                        name="webhook_events[]" value="<?php echo esc_attr($eventKey); ?>"
                                        <?php checked($checked); ?>>
                                    <span class="cmc-checkbox__label"><?php echo esc_html($eventLabel); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (!empty($integrations['webhook_url'])): ?>
                        <hr class="cmc-divider">
                        <button type="button" id="cmc-s-test-webhook" class="cmc-btn cmc-btn--secondary cmc-btn--sm">
                            <i class="ti ti-send"></i>
                            <?php esc_html_e('ارسال درخواست تست', 'campaignchi'); ?>
                        </button>
                    <?php endif; ?>
                </div>

                <?php $this->renderSaveButton('integrations', __('ذخیره تنظیمات یکپارچگی', 'campaignchi')); ?>

            </div>
        </div><!-- /#cmc-tab-integrations -->

        <!-- ======================================================
             TAB: MAINTENANCE
        ====================================================== -->
        <div class="cmc-tab-panel" id="cmc-tab-maintenance" hidden>
            <div class="cmc-stack cmc-stack--md">

                <!-- System Info -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div class="cmc-card__title"><?php esc_html_e('اطلاعات سیستم', 'campaignchi'); ?></div>
                    </div>
                    <div class="cmc-grid cmc-grid--2" style="gap:var(--cmc-space-3)">
                        <?php $this->renderInfoRow(__('نسخه پلاگین', 'campaignchi'), CMC_VERSION); ?>
                        <?php $this->renderInfoRow(__('نسخه دیتابیس', 'campaignchi'), $dbVersion); ?>
                        <?php $this->renderInfoRow(__('نسخه PHP', 'campaignchi'), PHP_VERSION); ?>
                        <?php $this->renderInfoRow(__('نسخه ووکامرس', 'campaignchi'), defined('WC_VERSION') ? WC_VERSION : '—'); ?>
                        <?php $this->renderInfoRow(__('نسخه وردپرس', 'campaignchi'), get_bloginfo('version')); ?>
                        <?php $this->renderInfoRow(__('تعداد کل کمپین‌ها', 'campaignchi'), (string) $totalCampaigns); ?>
                        <?php $this->renderInfoRow(__('کش قیمت‌گذاری', 'campaignchi'), $pricingCache ? __('فعال', 'campaignchi') : __('خالی', 'campaignchi')); ?>
                        <?php $this->renderInfoRow(__('کش کمپین‌ها', 'campaignchi'), $candidatesCache ? __('فعال', 'campaignchi') : __('خالی', 'campaignchi')); ?>
                    </div>
                </div>

                <!-- Data Cleanup -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('پاکسازی داده', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('عملیات زیر برگشت‌ناپذیر هستند. قبل از انجام، از دیتابیس بک‌آپ بگیرید.', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <div class="cmc-stack cmc-stack--md">

                        <!-- Old analytics stats -->
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:var(--cmc-space-3) 0;border-bottom:1px solid var(--cmc-border-light)">
                            <div>
                                <div style="font-size:var(--cmc-font-size-base);font-weight:600;color:var(--cmc-text-heading)"><?php esc_html_e('آمار قدیمی (بیش از ۹۰ روز)', 'campaignchi'); ?></div>
                                <div style="font-size:var(--cmc-font-size-sm);color:var(--cmc-text-muted);margin-top:2px"><?php esc_html_e('پاکسازی ردیف‌های قدیمی جدول cmc_campaign_stats', 'campaignchi'); ?></div>
                            </div>
                            <button type="button" class="cmc-btn cmc-btn--secondary cmc-btn--sm cmc-s-maintenance-action"
                                data-action="cleanup_old_stats">
                                <i class="ti ti-trash"></i>
                                <?php esc_html_e('پاکسازی', 'campaignchi'); ?>
                            </button>
                        </div>

                        <!-- Orphaned campaign rules -->
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:var(--cmc-space-3) 0;border-bottom:1px solid var(--cmc-border-light)">
                            <div>
                                <div style="font-size:var(--cmc-font-size-base);font-weight:600;color:var(--cmc-text-heading)"><?php esc_html_e('قوانین یتیم', 'campaignchi'); ?></div>
                                <div style="font-size:var(--cmc-font-size-sm);color:var(--cmc-text-muted);margin-top:2px"><?php esc_html_e('حذف ردیف‌های campaign_rules مرتبط با کمپین‌های حذف‌شده', 'campaignchi'); ?></div>
                            </div>
                            <button type="button" class="cmc-btn cmc-btn--secondary cmc-btn--sm cmc-s-maintenance-action"
                                data-action="cleanup_orphaned_rules">
                                <i class="ti ti-trash"></i>
                                <?php esc_html_e('پاکسازی', 'campaignchi'); ?>
                            </button>
                        </div>

                        <!-- Old campaign sales records -->
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:var(--cmc-space-3) 0;border-bottom:1px solid var(--cmc-border-light)">
                            <div>
                                <div style="font-size:var(--cmc-font-size-base);font-weight:600;color:var(--cmc-text-heading)"><?php esc_html_e('لاگ فروش قدیمی (بیش از یک سال)', 'campaignchi'); ?></div>
                                <div style="font-size:var(--cmc-font-size-sm);color:var(--cmc-text-muted);margin-top:2px"><?php esc_html_e('پاکسازی ردیف‌های قدیمی جدول cmc_campaign_sales (گزارش‌های تاریخی حذف می‌شوند)', 'campaignchi'); ?></div>
                            </div>
                            <button type="button" class="cmc-btn cmc-btn--secondary cmc-btn--sm cmc-s-maintenance-action"
                                data-action="cleanup_old_sales">
                                <i class="ti ti-trash"></i>
                                <?php esc_html_e('پاکسازی', 'campaignchi'); ?>
                            </button>
                        </div>

                        <!-- Flush all caches -->
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:var(--cmc-space-3) 0">
                            <div>
                                <div style="font-size:var(--cmc-font-size-base);font-weight:600;color:var(--cmc-text-heading)"><?php esc_html_e('پاکسازی همه کش‌ها', 'campaignchi'); ?></div>
                                <div style="font-size:var(--cmc-font-size-sm);color:var(--cmc-text-muted);margin-top:2px"><?php esc_html_e('حذف تمام transient‌های کمپین‌چی. سیستم در اولین بازدید بعدی خودبه‌خود rebuild می‌کند.', 'campaignchi'); ?></div>
                            </div>
                            <button type="button" class="cmc-btn cmc-btn--secondary cmc-btn--sm cmc-s-maintenance-action"
                                data-action="flush_all_caches">
                                <i class="ti ti-refresh"></i>
                                <?php esc_html_e('فلاش کش', 'campaignchi'); ?>
                            </button>
                        </div>

                    </div>
                </div>

                <!-- Danger Zone -->
                <div class="cmc-card" style="border-color:var(--cmc-danger);border-width:1.5px">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title" style="color:var(--cmc-danger)"><?php esc_html_e('منطقه خطر', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('این عملیات‌ها کاملاً برگشت‌ناپذیر هستند', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <div class="cmc-stack cmc-stack--md">

                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--cmc-space-4)">
                            <div>
                                <div style="font-size:var(--cmc-font-size-base);font-weight:600;color:var(--cmc-text-heading)"><?php esc_html_e('حذف همه کمپین‌های پایان‌یافته', 'campaignchi'); ?></div>
                                <div style="font-size:var(--cmc-font-size-sm);color:var(--cmc-text-muted);margin-top:2px"><?php esc_html_e('تمام کمپین‌ها با وضعیت "ended" به همراه آمار و محصولاتشان حذف می‌شوند', 'campaignchi'); ?></div>
                            </div>
                            <button type="button" class="cmc-btn cmc-btn--danger cmc-btn--sm cmc-s-danger-action"
                                data-action="delete_ended_campaigns"
                                style="flex-shrink:0">
                                <i class="ti ti-trash"></i>
                                <?php esc_html_e('حذف', 'campaignchi'); ?>
                            </button>
                        </div>

                        <hr class="cmc-divider--light" style="background:var(--cmc-danger-light)">

                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--cmc-space-4)">
                            <div>
                                <div style="font-size:var(--cmc-font-size-base);font-weight:600;color:var(--cmc-danger)"><?php esc_html_e('بازنشانی کامل پلاگین', 'campaignchi'); ?></div>
                                <div style="font-size:var(--cmc-font-size-sm);color:var(--cmc-text-muted);margin-top:2px"><?php esc_html_e('تمام جداول، گزینه‌ها، transient‌ها و تنظیمات حذف می‌شوند. پلاگین به حالت نصب تازه برمی‌گردد.', 'campaignchi'); ?></div>
                            </div>
                            <button type="button" class="cmc-btn cmc-btn--danger cmc-btn--sm cmc-s-danger-action"
                                data-action="factory_reset"
                                style="flex-shrink:0">
                                <i class="ti ti-alert-triangle"></i>
                                <?php esc_html_e('بازنشانی', 'campaignchi'); ?>
                            </button>
                        </div>

                    </div>
                </div>

            </div>
        </div><!-- /#cmc-tab-maintenance -->

        <?php $this->renderSettingsScript($general, $campaign, $performance, $access, $integrations); ?>
<?php
    }

    // -------------------------------------------------------
    // RENDER HELPERS
    // -------------------------------------------------------

    /**
     * Render a labeled toggle row (consistent with AppearancePage pattern).
     *
     * @param string $severity 'normal' | 'warning' — adds a visual hint for risky toggles
     */
    private function renderToggle(
        string $id,
        string $name,
        string $label,
        string $hint,
        bool $checked,
        string $severity = 'normal'
    ): void {
    ?>
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--cmc-space-4);padding:2px 0">
            <div>
                <div class="cmc-label" style="<?php echo $severity === 'warning' ? 'color:var(--cmc-warning)' : ''; ?>">
                    <?php if ($severity === 'warning'): ?>
                        <i class="ti ti-alert-triangle" style="font-size:12px;margin-left:3px"></i>
                    <?php endif; ?>
                    <?php echo esc_html($label); ?>
                </div>
                <div class="cmc-form-hint" style="margin-top:2px"><?php echo esc_html($hint); ?></div>
            </div>
            <label class="cmc-toggle" style="flex-shrink:0">
                <input type="checkbox" class="cmc-toggle__input" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" <?php checked($checked); ?>>
                <div class="cmc-toggle__track"><div class="cmc-toggle__thumb"></div></div>
            </label>
        </div>
    <?php
    }

    /**
     * Render a system-info table row.
     */
    private function renderInfoRow(string $label, string $value): void
    {
    ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:var(--cmc-space-2) var(--cmc-space-3);background:var(--cmc-surface-2);border-radius:var(--cmc-radius-sm)">
            <span style="font-size:var(--cmc-font-size-sm);color:var(--cmc-text-muted)"><?php echo esc_html($label); ?></span>
            <span style="font-size:var(--cmc-font-size-sm);font-weight:600;color:var(--cmc-text-heading);direction:ltr"><?php echo esc_html($value); ?></span>
        </div>
    <?php
    }

    /**
     * Render a cache status badge card.
     */
    private function renderCacheStatusBadge(string $label, string $transientKey, string $description): void
    {
        $isHot = (bool) get_transient($transientKey);
    ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:var(--cmc-space-3) var(--cmc-space-4);border:1px solid var(--cmc-border);border-radius:var(--cmc-radius-md)">
            <div>
                <div style="font-size:var(--cmc-font-size-sm);font-weight:600;color:var(--cmc-text-heading)"><?php echo esc_html($label); ?></div>
                <div style="font-size:var(--cmc-font-size-xs);color:var(--cmc-text-muted);font-family:monospace;margin-top:1px"><?php echo esc_html($description); ?></div>
            </div>
            <?php if ($isHot): ?>
                <span class="cmc-badge cmc-badge--active"><span class="cmc-badge__dot"></span><?php esc_html_e('کش شده', 'campaignchi'); ?></span>
            <?php else: ?>
                <span class="cmc-badge cmc-badge--draft"><?php esc_html_e('خالی', 'campaignchi'); ?></span>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Render a tab's save button with a section identifier for JS.
     */
    private function renderSaveButton(string $section, string $label): void
    {
    ?>
        <button type="button"
            class="cmc-btn cmc-btn--primary cmc-s-save-btn"
            data-section="<?php echo esc_attr($section); ?>"
            style="align-self:flex-start">
            <i class="ti ti-device-floppy"></i>
            <?php echo esc_html($label); ?>
        </button>
    <?php
    }

    /**
     * Collect all capability strings from all registered WP roles, sorted.
     * Used to populate the access control dropdowns.
     *
     * @return string[]
     */
    private function getAvailableCapabilities(): array
    {
        // Common WP + WC capabilities most relevant for a plugin like this
        $common = [
            'manage_options',
            'manage_woocommerce',
            'edit_posts',
            'publish_posts',
            'manage_categories',
            'moderate_comments',
            'upload_files',
        ];

        global $wp_roles;
        $all = $common;
        if (isset($wp_roles)) {
            foreach ($wp_roles->roles as $role) {
                foreach (array_keys($role['capabilities'] ?? []) as $cap) {
                    $all[] = $cap;
                }
            }
        }

        $all = array_values(array_unique($all));
        sort($all);

        return $all;
    }

    // -------------------------------------------------------
    // INLINE SCRIPT
    // -------------------------------------------------------

    /**
     * Emit the page-specific JS inline — keeps it co-located with the
     * markup and avoids a separate HTTP request for a small amount of code.
     */
    private function renderSettingsScript(
        array $general,
        array $campaign,
        array $performance,
        array $access,
        array $integrations
    ): void {
    ?>
        <script>
        (function () {
            'use strict';

            // -----------------------------------------------
            // Helpers
            // -----------------------------------------------

            function $ (id) { return document.getElementById(id); }

            /** Collect all named inputs/selects/checkboxes inside an element. */
            function collectSection (containerSelector) {
                var out = {};
                var container = document.querySelector(containerSelector);
                if (!container) return out;

                // Regular inputs and selects
                container.querySelectorAll('[name]').forEach(function (el) {
                    var name = el.getAttribute('name');
                    if (!name) return;

                    if (el.type === 'checkbox') {
                        // Multi-value checkboxes (webhook_events[])
                        if (name.endsWith('[]')) {
                            var key = name.slice(0, -2);
                            if (!Array.isArray(out[key])) out[key] = [];
                            if (el.checked) out[key].push(el.value);
                        } else {
                            out[name] = el.checked ? '1' : '0';
                        }
                    } else {
                        out[name] = el.value;
                    }
                });

                return out;
            }

            // -----------------------------------------------
            // Save buttons
            // -----------------------------------------------

            document.querySelectorAll('.cmc-s-save-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var section   = btn.dataset.section;
                    var panelId   = 'cmc-tab-' + section;
                    var data      = collectSection('#' + panelId);
                    data.section  = section;

                    btn.classList.add('is-loading');
                    btn.disabled = true;

                    CMC.ajax('cmc_save_settings_section', data)
                        .then(function (res) {
                            if (res && res.success) {
                                CMC.toast(res.message || '<?php esc_html_e('ذخیره شد', 'campaignchi'); ?>', 'success');
                            } else {
                                CMC.toast((res && res.message) || '<?php esc_html_e('خطا در ذخیره‌سازی', 'campaignchi'); ?>', 'danger');
                            }
                        })
                        .catch(function () {
                            CMC.toast('<?php esc_html_e('خطا در اتصال به سرور', 'campaignchi'); ?>', 'danger');
                        })
                        .finally(function () {
                            btn.classList.remove('is-loading');
                            btn.disabled = false;
                        });
                });
            });

            // -----------------------------------------------
            // Maintenance actions
            // -----------------------------------------------

            document.querySelectorAll('.cmc-s-maintenance-action').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var action = btn.dataset.action;

                    CMC.confirm({
                        title   : '<?php esc_html_e('تأیید عملیات', 'campaignchi'); ?>',
                        body    : '<?php esc_html_e('این عملیات قابل بازگشت نیست. ادامه می‌دهید؟', 'campaignchi'); ?>',
                        sub     : '',
                        okLabel : '<?php esc_html_e('بله، انجام بده', 'campaignchi'); ?>',
                        okClass : 'cmc-btn--danger',
                        onConfirm: function () {
                            btn.classList.add('is-loading');
                            btn.disabled = true;

                            CMC.ajax('cmc_maintenance_action', { action_type: action })
                                .then(function (res) {
                                    CMC.toast(
                                        (res && res.message) || '<?php esc_html_e('انجام شد', 'campaignchi'); ?>',
                                        (res && res.success) ? 'success' : 'danger'
                                    );
                                    // Reload cache-status badges after flush
                                    if (action === 'flush_all_caches') {
                                        setTimeout(function () { window.location.reload(); }, 1200);
                                    }
                                })
                                .catch(function () {
                                    CMC.toast('<?php esc_html_e('خطا در اتصال', 'campaignchi'); ?>', 'danger');
                                })
                                .finally(function () {
                                    btn.classList.remove('is-loading');
                                    btn.disabled = false;
                                });
                        }
                    });
                });
            });

            // -----------------------------------------------
            // Danger zone actions
            // -----------------------------------------------

            document.querySelectorAll('.cmc-s-danger-action').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var action = btn.dataset.action;
                    var isReset = action === 'factory_reset';

                    CMC.confirm({
                        title   : isReset
                            ? '<?php esc_html_e('بازنشانی کامل پلاگین؟', 'campaignchi'); ?>'
                            : '<?php esc_html_e('حذف کمپین‌های پایان‌یافته؟', 'campaignchi'); ?>',
                        body    : isReset
                            ? '<?php esc_html_e('تمام داده‌ها، تنظیمات و جداول پلاگین برای همیشه حذف می‌شوند.', 'campaignchi'); ?>'
                            : '<?php esc_html_e('تمام کمپین‌های ended به همراه آمار آن‌ها حذف می‌شوند.', 'campaignchi'); ?>',
                        sub     : '<?php esc_html_e('این عملیات کاملاً برگشت‌ناپذیر است. قبل از ادامه از دیتابیس بک‌آپ بگیرید.', 'campaignchi'); ?>',
                        okLabel : '<?php esc_html_e('بله، مطمئنم', 'campaignchi'); ?>',
                        okClass : 'cmc-btn--danger',
                        onConfirm: function () {
                            btn.classList.add('is-loading');
                            btn.disabled = true;

                            CMC.ajax('cmc_maintenance_action', { action_type: action })
                                .then(function (res) {
                                    CMC.toast(
                                        (res && res.message) || '<?php esc_html_e('انجام شد', 'campaignchi'); ?>',
                                        (res && res.success) ? 'success' : 'danger'
                                    );
                                    if (isReset && res && res.success) {
                                        setTimeout(function () { window.location.href = '<?php echo esc_url(admin_url()); ?>'; }, 1500);
                                    }
                                })
                                .catch(function () {
                                    CMC.toast('<?php esc_html_e('خطا در اتصال', 'campaignchi'); ?>', 'danger');
                                })
                                .finally(function () {
                                    btn.classList.remove('is-loading');
                                    btn.disabled = false;
                                });
                        }
                    });
                });
            });

            // -----------------------------------------------
            // Flush all caches shortcut (performance tab)
            // -----------------------------------------------

            var flushAllBtn = $('cmc-s-flush-all');
            if (flushAllBtn) {
                flushAllBtn.addEventListener('click', function () {
                    flushAllBtn.classList.add('is-loading');
                    flushAllBtn.disabled = true;

                    CMC.ajax('cmc_maintenance_action', { action_type: 'flush_all_caches' })
                        .then(function (res) {
                            CMC.toast(
                                (res && res.message) || '<?php esc_html_e('کش‌ها پاک شدند', 'campaignchi'); ?>',
                                'success'
                            );
                            setTimeout(function () { window.location.reload(); }, 1000);
                        })
                        .catch(function () {
                            CMC.toast('<?php esc_html_e('خطا', 'campaignchi'); ?>', 'danger');
                        })
                        .finally(function () {
                            flushAllBtn.classList.remove('is-loading');
                            flushAllBtn.disabled = false;
                        });
                });
            }

            // -----------------------------------------------
            // Webhook test
            // -----------------------------------------------

            var testWebhookBtn = $('cmc-s-test-webhook');
            if (testWebhookBtn) {
                testWebhookBtn.addEventListener('click', function () {
                    testWebhookBtn.classList.add('is-loading');
                    testWebhookBtn.disabled = true;

                    CMC.ajax('cmc_test_webhook', {})
                        .then(function (res) {
                            CMC.toast(
                                (res && res.message) || '<?php esc_html_e('درخواست ارسال شد', 'campaignchi'); ?>',
                                (res && res.success) ? 'success' : 'danger'
                            );
                        })
                        .catch(function () {
                            CMC.toast('<?php esc_html_e('خطا در ارسال', 'campaignchi'); ?>', 'danger');
                        })
                        .finally(function () {
                            testWebhookBtn.classList.remove('is-loading');
                            testWebhookBtn.disabled = false;
                        });
                });
            }

            // -----------------------------------------------
            // Show/hide custom currency fields
            // -----------------------------------------------

            var priceFormatSel = $('cmc-s-price-format');
            var currencyWrap   = $('cmc-s-currency-wrap');
            var currencyPosWrap = $('cmc-s-currency-pos-wrap');

            function toggleCurrencyFields () {
                var show = priceFormatSel && priceFormatSel.value === 'custom';
                if (currencyWrap)    currencyWrap.style.opacity    = show ? '1' : '0.4';
                if (currencyPosWrap) currencyPosWrap.style.opacity = show ? '1' : '0.4';
            }

            if (priceFormatSel) {
                priceFormatSel.addEventListener('change', toggleCurrencyFields);
                toggleCurrencyFields();
            }

        })();
        </script>
<?php
    }
}