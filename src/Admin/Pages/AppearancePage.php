<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

use Msi\Campaignchi\Core\Application;
use Msi\Campaignchi\Templates\Services\SliderSettingsService;
use Msi\Campaignchi\Templates\TemplateRegistry;

/**
 * Appearance Page
 *
 * Global, site-wide defaults for the Campaign Slider feature: the master
 * on/off switch, the default skin used by bare `[campaignchi_slider]`
 * shortcodes, every cosmetic/behavioral default (colors, radius,
 * autoplay, toggles, CTA text), and the colors of the classic discount
 * badge shown on default WooCommerce shop-loop cards and the single
 * product page. These values are the global fallback layer in
 * SliderSettingsService::resolve() — any saved preset or inline
 * shortcode/widget attribute that does not explicitly set a given field
 * will pick up whatever is configured here, automatically and
 * retroactively (no need to edit every existing slider one by one).
 *
 * ⚠️ Markup here intentionally reuses ONLY the real, already-styled
 * design-system components (.cmc-form-group, .cmc-label, .cmc-input,
 * .cmc-select, .cmc-toggle, .cmc-row, .cmc-grid, ...) documented in
 * cmc-admin-panel.md, instead of inventing new ad-hoc classes — that
 * earlier approach is exactly what made a previous version of this
 * feature's admin UI look inconsistent with the rest of the panel.
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
class AppearancePage extends AbstractPage
{
    public function title(): string
    {
        return __('ظاهر', 'campaignchi');
    }

    public function render(): void
    {
        $settings = Application::getInstance()->make(SliderSettingsService::class);
        $global   = $settings->getGlobalSettings();
        $enabled  = $settings->getEnabledTemplates();
        ?>
        <div class="cmc-row cmc-row--between cmc-mb-5">
            <div>
                <h2 style="font-size:var(--cmc-font-size-xl);font-weight:700;color:var(--cmc-text-heading);margin:0">
                    <?php esc_html_e('ظاهر', 'campaignchi'); ?>
                </h2>
                <p style="color:var(--cmc-text-muted);font-size:var(--cmc-font-size-sm);margin:4px 0 0">
                    <?php esc_html_e('تنظیمات پیش‌فرض و سراسری اسلایدر کمپین و بج تخفیف کلاسیک. هر شورت‌کد یا ویجت المنتور که مقدار صریحی تعیین نکرده باشد، از همین مقادیر استفاده می‌کند.', 'campaignchi'); ?>
                </p>
            </div>
        </div>

        <div class="cmc-stack cmc-stack--md">

            <!-- ===== Master switch + slider defaults ===== -->
            <div class="cmc-card">
                <div class="cmc-card__header">
                    <div class="cmc-card__title"><?php esc_html_e('اسلایدر کمپین', 'campaignchi'); ?></div>
                </div>

                <div class="cmc-row cmc-row--between cmc-mb-4">
                    <div>
                        <div class="cmc-label"><?php esc_html_e('فعال‌سازی اسلایدر کمپین', 'campaignchi'); ?></div>
                        <div class="cmc-form-hint"><?php esc_html_e('در صورت غیرفعال بودن، هیچ شورت‌کد یا ویجتی در کل سایت نمایش داده نمی‌شود.', 'campaignchi'); ?></div>
                    </div>
                    <label class="cmc-toggle">
                        <input type="checkbox" class="cmc-toggle__input" id="cmc-a-master-enabled" <?php checked(!empty($global['master_enabled'])); ?>>
                        <div class="cmc-toggle__track"><div class="cmc-toggle__thumb"></div></div>
                    </label>
                </div>

                <div class="cmc-grid cmc-grid--3 cmc-mb-4">
                    <div class="cmc-form-group">
                        <label class="cmc-label" for="cmc-a-default-template"><?php esc_html_e('قالب پیش‌فرض', 'campaignchi'); ?></label>
                        <select id="cmc-a-default-template" class="cmc-select">
                            <?php foreach (TemplateRegistry::all() as $template): ?>
                                <option value="<?php echo esc_attr($template->id()); ?>"
                                    <?php selected($global['default_template'], $template->id()); ?>
                                    <?php disabled(!in_array($template->id(), $enabled, true)); ?>>
                                    <?php echo esc_html($template->label()); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cmc-form-group">
                        <label class="cmc-label" for="cmc-a-limit"><?php esc_html_e('تعداد محصولات پیش‌فرض', 'campaignchi'); ?></label>
                        <input type="number" id="cmc-a-limit" class="cmc-input" min="1" max="20" value="<?php echo esc_attr((string) $global['limit']); ?>">
                    </div>

                    <div class="cmc-form-group">
                        <label class="cmc-label" for="cmc-a-order"><?php esc_html_e('ترتیب نمایش پیش‌فرض', 'campaignchi'); ?></label>
                        <select id="cmc-a-order" class="cmc-select">
                            <option value="priority" <?php selected($global['order'], 'priority'); ?>><?php esc_html_e('اولویت پیش‌فرض کمپین', 'campaignchi'); ?></option>
                            <option value="newest" <?php selected($global['order'], 'newest'); ?>><?php esc_html_e('جدیدترین', 'campaignchi'); ?></option>
                            <option value="random" <?php selected($global['order'], 'random'); ?>><?php esc_html_e('تصادفی', 'campaignchi'); ?></option>
                        </select>
                    </div>

                    <div class="cmc-form-group">
                        <label class="cmc-label" for="cmc-a-primary-color"><?php esc_html_e('رنگ اصلی اسلایدر', 'campaignchi'); ?></label>
                        <input type="color" id="cmc-a-primary-color" class="cmc-input cmc-color-input" value="<?php echo esc_attr($global['primary_color']); ?>">
                    </div>

                    <div class="cmc-form-group">
                        <label class="cmc-label" for="cmc-a-accent-color"><?php esc_html_e('رنگ تاکیدی اسلایدر', 'campaignchi'); ?></label>
                        <input type="color" id="cmc-a-accent-color" class="cmc-input cmc-color-input" value="<?php echo esc_attr($global['accent_color']); ?>">
                    </div>

                    <div class="cmc-form-group">
                        <label class="cmc-label" for="cmc-a-radius"><?php esc_html_e('گردی گوشه‌ها (پیکسل)', 'campaignchi'); ?></label>
                        <input type="number" id="cmc-a-radius" class="cmc-input" min="0" max="40" value="<?php echo esc_attr((string) $global['radius']); ?>">
                    </div>

                    <div class="cmc-form-group">
                        <label class="cmc-label" for="cmc-a-autoplay-speed"><?php esc_html_e('سرعت پخش خودکار (میلی‌ثانیه)', 'campaignchi'); ?></label>
                        <input type="number" id="cmc-a-autoplay-speed" class="cmc-input" min="1000" max="15000" step="500" value="<?php echo esc_attr((string) $global['autoplay_speed']); ?>">
                    </div>

                    <div class="cmc-form-group" style="grid-column:span 2">
                        <label class="cmc-label" for="cmc-a-cta-text"><?php esc_html_e('متن دکمه CTA پیش‌فرض', 'campaignchi'); ?></label>
                        <input type="text" id="cmc-a-cta-text" class="cmc-input" value="<?php echo esc_attr($global['cta_text']); ?>">
                    </div>
                </div>

                <hr class="cmc-divider">

                <div class="cmc-grid cmc-grid--3">
                    <?php $this->renderToggleRow('cmc-a-autoplay', __('پخش خودکار', 'campaignchi'), !empty($global['autoplay'])); ?>
                    <?php $this->renderToggleRow('cmc-a-arrows', __('فلش‌های ناوبری', 'campaignchi'), !empty($global['arrows'])); ?>
                    <?php $this->renderToggleRow('cmc-a-dots', __('نقاط ناوبری', 'campaignchi'), !empty($global['dots'])); ?>
                    <?php $this->renderToggleRow('cmc-a-show-countdown', __('شمارش معکوس', 'campaignchi'), !empty($global['show_countdown'])); ?>
                    <?php $this->renderToggleRow('cmc-a-show-stock', __('نوار موجودی', 'campaignchi'), !empty($global['show_stock'])); ?>
                    <?php $this->renderToggleRow('cmc-a-dark-mode', __('حالت تیره', 'campaignchi'), !empty($global['dark_mode'])); ?>
                </div>
            </div>

            <!-- ===== Classic discount badge (shop loop + single product) ===== -->
            <div class="cmc-card">
                <div class="cmc-card__header">
                    <div>
                        <div class="cmc-card__title"><?php esc_html_e('بج تخفیف در کارت محصول', 'campaignchi'); ?></div>
                        <div class="cmc-card__subtitle"><?php esc_html_e('بجی که روی کارت محصول در فروشگاه و صفحه‌ی تک‌محصول، مستقل از اسلایدر، نمایش داده می‌شود.', 'campaignchi'); ?></div>
                    </div>
                </div>

                <div class="cmc-row cmc-row--between cmc-mb-4">
                    <div>
                        <div class="cmc-label"><?php esc_html_e('نمایش بج تخفیف کلاسیک', 'campaignchi'); ?></div>
                        <div class="cmc-form-hint"><?php esc_html_e('در صورت غیرفعال بودن، فقط اسلایدر کمپین بج درصد تخفیف را نشان می‌دهد، نه کارت‌های معمولی محصول.', 'campaignchi'); ?></div>
                    </div>
                    <label class="cmc-toggle">
                        <input type="checkbox" class="cmc-toggle__input" id="cmc-a-classic-badge-enabled" <?php checked(!empty($global['classic_badge_enabled'])); ?>>
                        <div class="cmc-toggle__track"><div class="cmc-toggle__thumb"></div></div>
                    </label>
                </div>

                <div class="cmc-grid cmc-grid--2">
                    <div class="cmc-form-group">
                        <label class="cmc-label" for="cmc-a-classic-badge-bg"><?php esc_html_e('رنگ پس‌زمینه‌ی بج', 'campaignchi'); ?></label>
                        <input type="color" id="cmc-a-classic-badge-bg" class="cmc-input cmc-color-input" value="<?php echo esc_attr($global['classic_badge_bg_color']); ?>">
                    </div>
                    <div class="cmc-form-group">
                        <label class="cmc-label" for="cmc-a-classic-badge-text"><?php esc_html_e('رنگ متن بج', 'campaignchi'); ?></label>
                        <input type="color" id="cmc-a-classic-badge-text" class="cmc-input cmc-color-input" value="<?php echo esc_attr($global['classic_badge_text_color']); ?>">
                    </div>
                </div>
            </div>

            <button type="button" class="cmc-btn cmc-btn--primary" id="cmc-a-save-btn" style="align-self:flex-start">
                <i class="ti ti-device-floppy"></i>
                <?php esc_html_e('ذخیره تنظیمات', 'campaignchi'); ?>
            </button>

        </div>
        <?php
        $this->renderStyles();
    }

    /** Shared "label + hint + toggle" row, matching the exact pattern already used elsewhere in the panel (see Admin\Pages\StubPages SettingsPage). */
    private function renderToggleRow(string $id, string $label, bool $checked): void
    {
        ?>
        <div class="cmc-row cmc-row--between">
            <div class="cmc-label"><?php echo esc_html($label); ?></div>
            <label class="cmc-toggle">
                <input type="checkbox" class="cmc-toggle__input" id="<?php echo esc_attr($id); ?>" <?php checked($checked); ?>>
                <div class="cmc-toggle__track"><div class="cmc-toggle__thumb"></div></div>
            </label>
        </div>
        <?php
    }

    /**
     * Only ONE small addition is needed beyond the real design system:
     * a color-input modifier, since base.css/components.css never style
     * `<input type="color">` specifically.
     */
    private function renderStyles(): void
    {
        ?>
        <style>
            .cmc-color-input { padding: 3px !important; height: 38px; cursor: pointer; }
        </style>
        <?php
    }
}