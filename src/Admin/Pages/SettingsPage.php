<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

use Msi\Campaignchi\Core\Application;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;

/**
 * Settings Page
 *
 * Central control panel for the Campaignchi plugin.
 *
 * IMPORTANT — every control on this page is wired to real behavior:
 *   - General      → admin-bar live-campaign badge (AdminServiceProvider)
 *   - Campaign     → discount ceilings (PriceCalculator), auto-expire status
 *                    and cron interval (PricingServiceProvider)
 *   - Performance  → cache TTLs (CampaignResolver / AnalyticsService) and
 *                    real transient maintenance
 *   - Maintenance  → real DB cleanup / cache flush / factory reset
 *
 * Settings that previously had no consumer anywhere in the codebase
 * (price/currency formatting, capability switching, REST API, webhooks,
 * audit log, unused engine toggles) were removed rather than shipped as
 * non-functional UI. Every option key here is read by real code.
 *
 * Option keys are prefixed with `cmc_settings_` to distinguish them from
 * the appearance/slider options (cmc_slider_*) and installer defaults.
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
class SettingsPage extends AbstractPage
{
    // -------------------------------------------------------
    // Option key constants — single source of truth
    // -------------------------------------------------------

    /** General behavior options. */
    public const OPT_GENERAL     = 'cmc_settings_general';

    /** Campaign-engine rules (discount ceilings, scheduling). */
    public const OPT_CAMPAIGN    = 'cmc_settings_campaign';

    /** Performance / caching options. */
    public const OPT_PERFORMANCE = 'cmc_settings_performance';

    // -------------------------------------------------------
    // Hardcoded defaults
    // -------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private static function defaultGeneral(): array
    {
        return [
            // Show the live-campaign count in the WP admin bar.
            'admin_bar_badge' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function defaultCampaign(): array
    {
        return [
            // Hard ceiling for percentage discounts (1-100). Enforced in PriceCalculator.
            'max_discount_percent'  => 90,
            // Hard ceiling for fixed-amount discounts (0 = unlimited). Enforced in PriceCalculator.
            'max_discount_fixed'    => 0,
            // How often the auto-transition cron fires (5|10|15|30 minutes).
            'cron_interval_minutes' => 5,
            // Status applied when a flash sale passes its end date ('ended'|'draft').
            'auto_expire_status'    => 'ended',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function defaultPerformance(): array
    {
        return [
            // Max TTL (seconds) for the product->campaign pricing map transient.
            'pricing_cache_ttl'    => 300,
            // TTL (seconds) for today's dashboard analytics cache.
            'analytics_cache_ttl'  => 60,
            // TTL (seconds) for the resolved campaign-candidates cache.
            'candidates_cache_ttl' => 600,
        ];
    }

    // -------------------------------------------------------
    // Public accessors — consumed by the rest of the system
    // -------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    public static function getGeneral(): array
    {
        $stored = get_option(self::OPT_GENERAL, []);
        return array_merge(self::defaultGeneral(), is_array($stored) ? $stored : []);
    }

    /**
     * @return array<string,mixed>
     */
    public static function getCampaign(): array
    {
        $stored = get_option(self::OPT_CAMPAIGN, []);
        return array_merge(self::defaultCampaign(), is_array($stored) ? $stored : []);
    }

    /**
     * @return array<string,mixed>
     */
    public static function getPerformance(): array
    {
        $stored = get_option(self::OPT_PERFORMANCE, []);
        return array_merge(self::defaultPerformance(), is_array($stored) ? $stored : []);
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
        $general     = self::getGeneral();
        $campaign    = self::getCampaign();
        $performance = self::getPerformance();

        // System status data for the maintenance card.
        $dbVersion       = get_option('cmc_db_version', '—');
        $pricingCache    = (bool) get_transient('cmc_pricing_map_v1');
        $candidatesCache = (bool) get_transient('cmc_campaign_candidates_v1');
        $totalCampaigns  = 0;
        try {
            $repo = Application::getInstance()->make(CampaignRepository::class);
            $totalCampaigns = $repo->paginate(['per_page' => 1])['total'];
        } catch (\Throwable) {
            // Repository unavailable — show 0 rather than fatally failing the page.
        }
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
                    <?php esc_html_e('کنترل رفتار، موتور کمپین و عملکرد پلاگین. هر بخش به‌صورت مستقل ذخیره می‌شود.', 'campaignchi'); ?>
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

                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('نوار مدیریت', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('نمایش وضعیت زنده‌ی کمپین‌ها در نوار بالای وردپرس', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <?php $this->renderToggle(
                        'cmc-s-adminbar-badge', 'admin_bar_badge',
                        __('نشانگر نوار ادمین', 'campaignchi'),
                        __('نمایش تعداد کمپین‌های فعال در نوار مدیریت وردپرس (برای کاربران دارای دسترسی مدیریت)', 'campaignchi'),
                        (bool) $general['admin_bar_badge']
                    ); ?>
                </div>

                <?php $this->renderSaveButton('general', __('ذخیره تنظیمات عمومی', 'campaignchi')); ?>

            </div>
        </div><!-- /#cmc-tab-general -->

        <!-- ======================================================
             TAB: CAMPAIGN ENGINE
        ====================================================== -->
        <div class="cmc-tab-panel" id="cmc-tab-campaign" hidden>
            <div class="cmc-stack cmc-stack--md">

                <!-- Discount Ceilings -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div>
                            <div class="cmc-card__title"><?php esc_html_e('سقف تخفیف', 'campaignchi'); ?></div>
                            <div class="cmc-card__subtitle"><?php esc_html_e('محدودیت ایمنی روی میزان تخفیفی که هر کمپین می‌تواند اعمال کند', 'campaignchi'); ?></div>
                        </div>
                    </div>

                    <div class="cmc-grid cmc-grid--2">

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-max-pct"><?php esc_html_e('حداکثر تخفیف درصدی', 'campaignchi'); ?></label>
                            <div class="cmc-input-wrap">
                                <i class="ti ti-percentage cmc-input-wrap__icon"></i>
                                <input type="number" id="cmc-s-max-pct" name="max_discount_percent" class="cmc-input"
                                    min="1" max="100" value="<?php echo esc_attr((string) $campaign['max_discount_percent']); ?>">
                            </div>
                            <span class="cmc-form-hint"><?php esc_html_e('قیمت نهایی هیچ محصولی بیش از این درصد کاهش نمی‌یابد (۱ تا ۱۰۰)', 'campaignchi'); ?></span>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-max-fixed"><?php esc_html_e('حداکثر تخفیف ثابت', 'campaignchi'); ?></label>
                            <div class="cmc-input-wrap">
                                <i class="ti ti-cash cmc-input-wrap__icon"></i>
                                <input type="number" id="cmc-s-max-fixed" name="max_discount_fixed" class="cmc-input"
                                    min="0" step="1000" value="<?php echo esc_attr((string) $campaign['max_discount_fixed']); ?>"
                                    placeholder="<?php esc_attr_e('۰ = بدون محدودیت', 'campaignchi'); ?>">
                            </div>
                            <span class="cmc-form-hint"><?php esc_html_e('سقف مبلغ تخفیف ثابت. صفر یعنی بدون محدودیت.', 'campaignchi'); ?></span>
                        </div>

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

                    <div class="cmc-grid cmc-grid--2">

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-cron-interval"><?php esc_html_e('تناوب کران (دقیقه)', 'campaignchi'); ?></label>
                            <select id="cmc-s-cron-interval" name="cron_interval_minutes" class="cmc-select">
                                <?php foreach ([5, 10, 15, 30] as $min): ?>
                                    <option value="<?php echo (int) $min; ?>" <?php selected((int) $campaign['cron_interval_minutes'], $min); ?>>
                                        <?php echo esc_html(sprintf(__('هر %d دقیقه', 'campaignchi'), $min)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="cmc-form-hint"><?php esc_html_e('فاصله‌ی بین بررسی شروع/پایان فلش‌سیل‌ها. کران بلافاصله بازنشانی می‌شود.', 'campaignchi'); ?></span>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-s-expire-status"><?php esc_html_e('وضعیت پس از انقضا', 'campaignchi'); ?></label>
                            <select id="cmc-s-expire-status" name="auto_expire_status" class="cmc-select">
                                <option value="ended" <?php selected($campaign['auto_expire_status'], 'ended'); ?>><?php esc_html_e('پایان‌یافته', 'campaignchi'); ?></option>
                                <option value="draft" <?php selected($campaign['auto_expire_status'], 'draft'); ?>><?php esc_html_e('پیش‌نویس', 'campaignchi'); ?></option>
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
                                min="20" max="3600" step="10"
                                value="<?php echo esc_attr((string) $performance['pricing_cache_ttl']); ?>">
                            <span class="cmc-form-hint"><?php esc_html_e('سقف TTL نقشه‌ی product→campaign. پیشنهاد: ۳۰۰ ثانیه', 'campaignchi'); ?></span>
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
                </div>

                <?php $this->renderSaveButton('performance', __('ذخیره تنظیمات پرفورمنس', 'campaignchi')); ?>

            </div>
        </div><!-- /#cmc-tab-performance -->

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
                <div class="cmc-card" style="border-color:var(--cmc-danger);border-width:1.5px; margin-bottom:30px;">
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

        <?php $this->renderSettingsScript(); ?>
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
     * Render a cache status badge card backed by a real transient.
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

    // -------------------------------------------------------
    // INLINE SCRIPT
    // -------------------------------------------------------

    /**
     * Emit the page-specific JS inline — keeps it co-located with the
     * markup and avoids a separate HTTP request for a small amount of code.
     */
    private function renderSettingsScript(): void
    {
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

                container.querySelectorAll('[name]').forEach(function (el) {
                    var name = el.getAttribute('name');
                    if (!name) return;

                    if (el.type === 'checkbox') {
                        out[name] = el.checked ? '1' : '0';
                    } else {
                        out[name] = el.value;
                    }
                });

                return out;
            }

            // -----------------------------------------------
            // Save buttons (one per settings section)
            // -----------------------------------------------

            document.querySelectorAll('.cmc-s-save-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var section  = btn.dataset.section;
                    var panelId  = 'cmc-tab-' + section;
                    var data     = collectSection('#' + panelId);
                    data.section = section;

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
            // Maintenance actions (cleanup operations)
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
                                    // Reload cache-status badges after a flush.
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
                    var action  = btn.dataset.action;
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
            // "Flush all caches" shortcut on the performance tab
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

        })();
        </script>
<?php
    }
}
