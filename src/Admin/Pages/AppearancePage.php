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
 * shortcodes, and every cosmetic/behavioral default (colors, radius,
 * autoplay, toggles, CTA text). These values are the global fallback
 * layer in SliderSettingsService::resolve() — any saved preset or
 * inline shortcode/widget attribute that does not explicitly set a given
 * field will pick up whatever is configured here, automatically and
 * retroactively (no need to edit every existing slider one by one).
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
class AppearancePage extends AbstractPage
{
    public function render(): void
    {
        $settings = Application::getInstance()->make(SliderSettingsService::class);
        $global   = $settings->getGlobalSettings();
        $enabled  = $settings->getEnabledTemplates();
        ?>
        <div class="cmc-page">
            <div class="cmc-page__header">
                <h1><?php esc_html_e('ظاهر', 'campaignchi'); ?></h1>
                <p class="cmc-page__subtitle">
                    <?php esc_html_e('تنظیمات پیش‌فرض و سراسری اسلایدر کمپین. هر شورت‌کد یا ویجت المنتور که مقدار صریحی برای یک گزینه تعیین نکرده باشد، از همین مقادیر استفاده می‌کند.', 'campaignchi'); ?>
                </p>
            </div>

            <div class="cmc-card">
                <div class="cmc-card__body">

                    <label class="cmc-switch-row">
                        <span>
                            <strong><?php esc_html_e('فعال‌سازی اسلایدر کمپین', 'campaignchi'); ?></strong>
                            <small><?php esc_html_e('در صورت غیرفعال بودن، هیچ شورت‌کد یا ویجتی در کل سایت نمایش داده نمی‌شود.', 'campaignchi'); ?></small>
                        </span>
                        <input type="checkbox" id="cmc-a-master-enabled" <?php checked(!empty($global['master_enabled'])); ?>>
                    </label>

                    <div class="cmc-form-grid">
                        <div class="cmc-field">
                            <label for="cmc-a-default-template"><?php esc_html_e('قالب پیش‌فرض', 'campaignchi'); ?></label>
                            <select id="cmc-a-default-template">
                                <?php foreach (TemplateRegistry::all() as $template): ?>
                                    <option value="<?php echo esc_attr($template->id()); ?>"
                                        <?php selected($global['default_template'], $template->id()); ?>
                                        <?php disabled(!in_array($template->id(), $enabled, true)); ?>>
                                        <?php echo esc_html($template->label()); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="cmc-field">
                            <label for="cmc-a-primary-color"><?php esc_html_e('رنگ اصلی', 'campaignchi'); ?></label>
                            <input type="color" id="cmc-a-primary-color" value="<?php echo esc_attr($global['primary_color']); ?>">
                        </div>

                        <div class="cmc-field">
                            <label for="cmc-a-accent-color"><?php esc_html_e('رنگ تاکیدی', 'campaignchi'); ?></label>
                            <input type="color" id="cmc-a-accent-color" value="<?php echo esc_attr($global['accent_color']); ?>">
                        </div>

                        <div class="cmc-field">
                            <label for="cmc-a-radius"><?php esc_html_e('گردی گوشه‌ها (پیکسل)', 'campaignchi'); ?></label>
                            <input type="number" id="cmc-a-radius" min="0" max="40" value="<?php echo esc_attr((string) $global['radius']); ?>">
                        </div>

                        <div class="cmc-field">
                            <label for="cmc-a-limit"><?php esc_html_e('تعداد محصولات پیش‌فرض', 'campaignchi'); ?></label>
                            <input type="number" id="cmc-a-limit" min="1" max="20" value="<?php echo esc_attr((string) $global['limit']); ?>">
                        </div>

                        <div class="cmc-field">
                            <label for="cmc-a-order"><?php esc_html_e('ترتیب نمایش پیش‌فرض', 'campaignchi'); ?></label>
                            <select id="cmc-a-order">
                                <option value="priority" <?php selected($global['order'], 'priority'); ?>><?php esc_html_e('اولویت پیش‌فرض کمپین', 'campaignchi'); ?></option>
                                <option value="newest" <?php selected($global['order'], 'newest'); ?>><?php esc_html_e('جدیدترین', 'campaignchi'); ?></option>
                                <option value="random" <?php selected($global['order'], 'random'); ?>><?php esc_html_e('تصادفی', 'campaignchi'); ?></option>
                            </select>
                        </div>

                        <div class="cmc-field">
                            <label for="cmc-a-autoplay-speed"><?php esc_html_e('سرعت پخش خودکار (میلی‌ثانیه)', 'campaignchi'); ?></label>
                            <input type="number" id="cmc-a-autoplay-speed" min="1000" max="15000" step="500" value="<?php echo esc_attr((string) $global['autoplay_speed']); ?>">
                        </div>

                        <div class="cmc-field">
                            <label for="cmc-a-cta-text"><?php esc_html_e('متن دکمه CTA پیش‌فرض', 'campaignchi'); ?></label>
                            <input type="text" id="cmc-a-cta-text" value="<?php echo esc_attr($global['cta_text']); ?>">
                        </div>
                    </div>

                    <div class="cmc-toggle-grid">
                        <label class="cmc-switch-row cmc-switch-row--compact">
                            <span><?php esc_html_e('پخش خودکار', 'campaignchi'); ?></span>
                            <input type="checkbox" id="cmc-a-autoplay" <?php checked(!empty($global['autoplay'])); ?>>
                        </label>
                        <label class="cmc-switch-row cmc-switch-row--compact">
                            <span><?php esc_html_e('فلش‌های ناوبری', 'campaignchi'); ?></span>
                            <input type="checkbox" id="cmc-a-arrows" <?php checked(!empty($global['arrows'])); ?>>
                        </label>
                        <label class="cmc-switch-row cmc-switch-row--compact">
                            <span><?php esc_html_e('نقاط ناوبری', 'campaignchi'); ?></span>
                            <input type="checkbox" id="cmc-a-dots" <?php checked(!empty($global['dots'])); ?>>
                        </label>
                        <label class="cmc-switch-row cmc-switch-row--compact">
                            <span><?php esc_html_e('شمارش معکوس', 'campaignchi'); ?></span>
                            <input type="checkbox" id="cmc-a-show-countdown" <?php checked(!empty($global['show_countdown'])); ?>>
                        </label>
                        <label class="cmc-switch-row cmc-switch-row--compact">
                            <span><?php esc_html_e('نوار موجودی', 'campaignchi'); ?></span>
                            <input type="checkbox" id="cmc-a-show-stock" <?php checked(!empty($global['show_stock'])); ?>>
                        </label>
                        <label class="cmc-switch-row cmc-switch-row--compact">
                            <span><?php esc_html_e('حالت تیره', 'campaignchi'); ?></span>
                            <input type="checkbox" id="cmc-a-dark-mode" <?php checked(!empty($global['dark_mode'])); ?>>
                        </label>
                    </div>

                    <div class="cmc-card__footer">
                        <button type="button" class="cmc-btn cmc-btn--primary" id="cmc-a-save-btn">
                            <?php esc_html_e('ذخیره تنظیمات', 'campaignchi'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $this->renderStyles();
    }

    /** Page-scoped styles, following the same inline-<style> convention already used by CampaignsPage. */
    private function renderStyles(): void
    {
        ?>
        <style>
            .cmc-switch-row { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 0; border-bottom:1px solid #eef0f3; }
            .cmc-switch-row small { display:block; color:#8d93a1; font-size:12px; margin-top:2px; }
            .cmc-switch-row--compact { border-bottom:none; padding:8px 0; }
            .cmc-form-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; margin:18px 0; }
            .cmc-toggle-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:4px 24px; margin:10px 0 18px; }
            .cmc-field label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:#1a1d24; }
            .cmc-field input[type="text"], .cmc-field input[type="number"], .cmc-field select { width:100%; }
            .cmc-field input[type="color"] { width:100%; height:38px; padding:2px; }
        </style>
        <?php
    }
}
