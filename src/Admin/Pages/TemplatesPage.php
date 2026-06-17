<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

use Msi\Campaignchi\Core\Application;
use Msi\Campaignchi\Templates\Repositories\SliderRepository;
use Msi\Campaignchi\Templates\Services\SliderSettingsService;
use Msi\Campaignchi\Templates\Shortcode\CampaignSliderShortcode;
use Msi\Campaignchi\Templates\TemplateRegistry;

/**
 * Templates Page
 *
 * Two things live here:
 *
 *   1. The template gallery — one card per registered skin
 *      (TemplateRegistry::all()), each with an enable/disable toggle and
 *      a "build a slider with this template" button that opens the
 *      builder modal pre-selected to that skin.
 *
 *   2. The saved sliders table — every preset created via the builder,
 *      with its ready-to-paste shortcode, and Edit/Delete actions. These
 *      same presets are also selectable from the Elementor widget's
 *      "use saved preset" dropdown, so editing one here updates it
 *      everywhere it's used, instantly.
 *
 * All interactivity (toggles, modal, live preview, save/delete/copy) is
 * handled by assets/js/templates-admin.js — this class only renders markup
 * and bootstrap data.
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
class TemplatesPage extends AbstractPage
{
    public function render(): void
    {
        $app      = Application::getInstance();
        $settings = $app->make(SliderSettingsService::class);
        $sliders  = $app->make(SliderRepository::class);

        $enabledTemplates = $settings->getEnabledTemplates();
        $allSliders       = $sliders->all();
        ?>
        <div class="cmc-page">
            <div class="cmc-page__header">
                <h1><?php esc_html_e('قالب‌ها', 'campaignchi'); ?></h1>
                <p class="cmc-page__subtitle">
                    <?php esc_html_e('یکی از ۵ قالب اسلایدر کمپین را انتخاب کنید، آن را شخصی‌سازی کنید و یک شورت‌کد یا ویجت المنتور آماده دریافت کنید.', 'campaignchi'); ?>
                </p>
            </div>

            <?php $this->renderGallery($enabledTemplates); ?>

            <div class="cmc-alert cmc-alert--info" style="margin:20px 0;">
                <i class="ti ti-info-circle"></i>
                <?php esc_html_e('هر اسلایدر ذخیره‌شده در جدول زیر، در ویجت «اسلایدر کمپین کمپین‌چی» داخل المنتور هم به‌صورت یک گزینه‌ی «استفاده از پریست» قابل انتخاب است.', 'campaignchi'); ?>
            </div>

            <?php $this->renderSlidersTable($allSliders); ?>
        </div>

        <?php $this->renderBuilderModal(); ?>

        <script>
            window.CMC_TEMPLATES_DATA = <?php echo wp_json_encode(['enabledTemplates' => $enabledTemplates]); ?>;
        </script>

        <?php $this->renderStyles(); ?>
        <?php
    }

    /** @param string[] $enabledTemplates */
    private function renderGallery(array $enabledTemplates): void
    {
        ?>
        <div class="cmc-template-gallery">
            <?php foreach (TemplateRegistry::all() as $template): ?>
                <?php $isEnabled = in_array($template->id(), $enabledTemplates, true); ?>
                <div class="cmc-template-card<?php echo $isEnabled ? '' : ' is-disabled'; ?>" data-template="<?php echo esc_attr($template->id()); ?>">
                    <div class="cmc-template-card__swatch" style="background:<?php echo esc_attr($template->previewGradient()); ?>">
                        <i class="ti <?php echo esc_attr($template->previewIcon()); ?>"></i>
                    </div>

                    <div class="cmc-template-card__body">
                        <div class="cmc-template-card__head">
                            <h3><?php echo esc_html($template->label()); ?></h3>
                            <label class="cmc-mini-switch" title="<?php esc_attr_e('فعال/غیرفعال در انتخابگرها', 'campaignchi'); ?>">
                                <input type="checkbox"
                                       class="cmc-template-enable-toggle"
                                       data-template="<?php echo esc_attr($template->id()); ?>"
                                       <?php checked($isEnabled); ?>>
                            </label>
                        </div>
                        <p><?php echo esc_html($template->description()); ?></p>

                        <button type="button"
                                class="cmc-btn cmc-btn--secondary cmc-template-use-btn"
                                data-template="<?php echo esc_attr($template->id()); ?>"
                                <?php disabled(!$isEnabled); ?>>
                            <i class="ti ti-plus"></i>
                            <?php esc_html_e('ساخت اسلایدر با این قالب', 'campaignchi'); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** @param array<int, array<string,mixed>> $sliders */
    private function renderSlidersTable(array $sliders): void
    {
        ?>
        <div class="cmc-card">
            <div class="cmc-card__header">
                <h2><?php esc_html_e('اسلایدرهای ذخیره‌شده', 'campaignchi'); ?></h2>
            </div>

            <?php if (empty($sliders)): ?>
                <div class="cmc-empty-state">
                    <i class="ti ti-carousel-horizontal"></i>
                    <p><?php esc_html_e('هنوز هیچ اسلایدری نساخته‌اید. از یکی از قالب‌های بالا شروع کنید.', 'campaignchi'); ?></p>
                </div>
            <?php else: ?>
                <table class="cmc-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('عنوان', 'campaignchi'); ?></th>
                            <th><?php esc_html_e('قالب', 'campaignchi'); ?></th>
                            <th><?php esc_html_e('شورت‌کد', 'campaignchi'); ?></th>
                            <th><?php esc_html_e('عملیات', 'campaignchi'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sliders as $slider): ?>
                            <?php
                            $template   = TemplateRegistry::get($slider['template']);
                            $shortcode  = sprintf('[%s id="%d"]', CampaignSliderShortcode::TAG, $slider['id']);
                            ?>
                            <tr>
                                <td><?php echo esc_html($slider['title']); ?></td>
                                <td>
                                    <span class="cmc-badge"><?php echo esc_html($template ? $template->label() : $slider['template']); ?></span>
                                </td>
                                <td>
                                    <code class="cmc-shortcode-tag"><?php echo esc_html($shortcode); ?></code>
                                    <button type="button" class="cmc-icon-btn cmc-slider-copy-btn" data-shortcode="<?php echo esc_attr($shortcode); ?>" title="<?php esc_attr_e('کپی شورت‌کد', 'campaignchi'); ?>">
                                        <i class="ti ti-copy"></i>
                                    </button>
                                </td>
                                <td class="cmc-table__actions">
                                    <button type="button" class="cmc-icon-btn cmc-slider-edit-btn" data-id="<?php echo esc_attr((string) $slider['id']); ?>" title="<?php esc_attr_e('ویرایش', 'campaignchi'); ?>">
                                        <i class="ti ti-pencil"></i>
                                    </button>
                                    <button type="button" class="cmc-icon-btn cmc-icon-btn--danger cmc-slider-delete-btn" data-id="<?php echo esc_attr((string) $slider['id']); ?>" title="<?php esc_attr_e('حذف', 'campaignchi'); ?>">
                                        <i class="ti ti-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderBuilderModal(): void
    {
        ?>
        <div class="cmc-modal" id="cmc-slider-builder-modal">
            <div class="cmc-modal__backdrop"></div>
            <div class="cmc-modal__dialog cmc-modal__dialog--wide">
                <div class="cmc-modal__header">
                    <h3><?php esc_html_e('ساخت / ویرایش اسلایدر', 'campaignchi'); ?></h3>
                    <button type="button" class="cmc-icon-btn" id="cmc-slider-modal-close-btn"><i class="ti ti-x"></i></button>
                </div>

                <div class="cmc-modal__body cmc-builder-grid">
                    <div class="cmc-builder-form">
                        <div class="cmc-field">
                            <label for="cmc-f-title"><?php esc_html_e('عنوان اسلایدر', 'campaignchi'); ?></label>
                            <input type="text" id="cmc-f-title" placeholder="<?php esc_attr_e('مثلاً: اسلایدر فلش‌سیل صفحه اصلی', 'campaignchi'); ?>">
                        </div>

                        <div class="cmc-form-grid">
                            <div class="cmc-field">
                                <label for="cmc-f-template"><?php esc_html_e('قالب', 'campaignchi'); ?></label>
                                <select id="cmc-f-template">
                                    <?php foreach (TemplateRegistry::all() as $template): ?>
                                        <option value="<?php echo esc_attr($template->id()); ?>"><?php echo esc_html($template->label()); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="cmc-field">
                                <label for="cmc-f-campaign"><?php esc_html_e('کمپین', 'campaignchi'); ?></label>
                                <select id="cmc-f-campaign">
                                    <option value="0"><?php esc_html_e('— انتخاب خودکار (بالاترین اولویت) —', 'campaignchi'); ?></option>
                                </select>
                            </div>

                            <div class="cmc-field">
                                <label for="cmc-f-limit"><?php esc_html_e('تعداد محصولات', 'campaignchi'); ?></label>
                                <input type="number" id="cmc-f-limit" min="1" max="20" value="8">
                            </div>

                            <div class="cmc-field">
                                <label for="cmc-f-order"><?php esc_html_e('ترتیب نمایش', 'campaignchi'); ?></label>
                                <select id="cmc-f-order">
                                    <option value="priority"><?php esc_html_e('اولویت پیش‌فرض کمپین', 'campaignchi'); ?></option>
                                    <option value="newest"><?php esc_html_e('جدیدترین', 'campaignchi'); ?></option>
                                    <option value="random"><?php esc_html_e('تصادفی', 'campaignchi'); ?></option>
                                </select>
                            </div>

                            <div class="cmc-field">
                                <label for="cmc-f-autoplay-speed"><?php esc_html_e('سرعت پخش خودکار (ms)', 'campaignchi'); ?></label>
                                <input type="number" id="cmc-f-autoplay-speed" min="1000" max="15000" step="500" value="4000">
                            </div>

                            <div class="cmc-field">
                                <label for="cmc-f-primary-color"><?php esc_html_e('رنگ اصلی', 'campaignchi'); ?></label>
                                <input type="color" id="cmc-f-primary-color" value="#6C47FF">
                            </div>

                            <div class="cmc-field">
                                <label for="cmc-f-accent-color"><?php esc_html_e('رنگ تاکیدی', 'campaignchi'); ?></label>
                                <input type="color" id="cmc-f-accent-color" value="#FF6B35">
                            </div>

                            <div class="cmc-field">
                                <label for="cmc-f-radius"><?php esc_html_e('گردی گوشه‌ها', 'campaignchi'); ?></label>
                                <input type="number" id="cmc-f-radius" min="0" max="40" value="16">
                            </div>

                            <div class="cmc-field">
                                <label for="cmc-f-cta-text"><?php esc_html_e('متن دکمه CTA', 'campaignchi'); ?></label>
                                <input type="text" id="cmc-f-cta-text" placeholder="<?php esc_attr_e('مشاهده محصول', 'campaignchi'); ?>">
                            </div>

                            <div class="cmc-field">
                                <label for="cmc-f-badge-text"><?php esc_html_e('متن بج تخفیف (دلخواه)', 'campaignchi'); ?></label>
                                <input type="text" id="cmc-f-badge-text" placeholder="<?php esc_attr_e('خالی = درصد خودکار', 'campaignchi'); ?>">
                            </div>
                        </div>

                        <div class="cmc-toggle-grid">
                            <label class="cmc-switch-row cmc-switch-row--compact"><span><?php esc_html_e('پخش خودکار', 'campaignchi'); ?></span><input type="checkbox" id="cmc-f-autoplay" checked></label>
                            <label class="cmc-switch-row cmc-switch-row--compact"><span><?php esc_html_e('چرخه پیوسته', 'campaignchi'); ?></span><input type="checkbox" id="cmc-f-loop" checked></label>
                            <label class="cmc-switch-row cmc-switch-row--compact"><span><?php esc_html_e('فلش‌های ناوبری', 'campaignchi'); ?></span><input type="checkbox" id="cmc-f-arrows" checked></label>
                            <label class="cmc-switch-row cmc-switch-row--compact"><span><?php esc_html_e('نقاط ناوبری', 'campaignchi'); ?></span><input type="checkbox" id="cmc-f-dots" checked></label>
                            <label class="cmc-switch-row cmc-switch-row--compact"><span><?php esc_html_e('شمارش معکوس', 'campaignchi'); ?></span><input type="checkbox" id="cmc-f-show-countdown" checked></label>
                            <label class="cmc-switch-row cmc-switch-row--compact"><span><?php esc_html_e('نوار موجودی', 'campaignchi'); ?></span><input type="checkbox" id="cmc-f-show-stock" checked></label>
                            <label class="cmc-switch-row cmc-switch-row--compact"><span><?php esc_html_e('حالت تیره', 'campaignchi'); ?></span><input type="checkbox" id="cmc-f-dark-mode"></label>
                        </div>
                    </div>

                    <div class="cmc-builder-preview">
                        <div class="cmc-builder-preview__label"><?php esc_html_e('پیش‌نمایش زنده', 'campaignchi'); ?></div>
                        <div id="cmc-slider-preview-pane" class="cmc-builder-preview__pane"></div>
                    </div>
                </div>

                <div class="cmc-modal__footer">
                    <button type="button" class="cmc-btn cmc-btn--ghost" id="cmc-slider-cancel-btn"><?php esc_html_e('انصراف', 'campaignchi'); ?></button>
                    <button type="button" class="cmc-btn cmc-btn--primary" id="cmc-slider-save-btn"><?php esc_html_e('ذخیره اسلایدر', 'campaignchi'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderStyles(): void
    {
        ?>
        <style>
            .cmc-template-gallery { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:18px; margin:20px 0; }
            .cmc-template-card { border:1px solid #e9eaee; border-radius:16px; overflow:hidden; background:#fff; transition:opacity .15s ease; }
            .cmc-template-card.is-disabled { opacity:.5; }
            .cmc-template-card__swatch { height:90px; display:flex; align-items:center; justify-content:center; font-size:30px; color:#fff; }
            .cmc-template-card__body { padding:14px 16px; }
            .cmc-template-card__head { display:flex; align-items:center; justify-content:space-between; gap:8px; }
            .cmc-template-card__head h3 { font-size:15px; margin:0; }
            .cmc-template-card__body p { font-size:12.5px; color:#7d8390; margin:8px 0 14px; line-height:1.7; min-height:54px; }
            .cmc-mini-switch input { transform:scale(0.85); }

            .cmc-empty-state { text-align:center; padding:50px 20px; color:#9aa0ac; }
            .cmc-empty-state i { font-size:34px; display:block; margin-bottom:10px; }

            .cmc-shortcode-tag { background:#f4f5f7; padding:4px 8px; border-radius:6px; font-size:12px; direction:ltr; display:inline-block; }

            .cmc-builder-grid { display:grid; grid-template-columns:1.4fr 1fr; gap:24px; align-items:start; }
            .cmc-builder-preview { background:#11151c; border-radius:14px; padding:16px; min-height:280px; }
            .cmc-builder-preview__label { color:#9aa0ac; font-size:12px; margin-bottom:10px; }
            .cmc-builder-preview__pane { min-height:220px; }
            .cmc-modal__dialog--wide { max-width:980px; }

            @media (max-width: 900px) {
                .cmc-builder-grid { grid-template-columns:1fr; }
            }
        </style>
        <?php
    }
}
