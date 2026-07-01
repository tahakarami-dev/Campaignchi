<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

use Msi\Campaignchi\Core\Application;
use Msi\Campaignchi\Templates\Repositories\SliderRepository;
use Msi\Campaignchi\Templates\Services\SliderSettingsService;
use Msi\Campaignchi\Templates\Shortcode\CampaignSliderShortcode;
use Msi\Campaignchi\Templates\TemplateRegistry;

if (!defined('ABSPATH')) {
    exit;
}

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
 * ⚠️ Every modal/form/table/button element below reuses the REAL,
 * already-styled design-system components documented in
 * cmc-admin-panel.md (.cmc-modal-overlay/.cmc-modal, .cmc-form-group,
 * .cmc-input, .cmc-select, .cmc-toggle, .cmc-color-field, .cmc-table,
 * .cmc-empty, the .cmc-btn family...). A previous version of this page
 * invented its own competing classes (.cmc-icon-btn, .cmc-field,
 * .cmc-modal__dialog, ...) that were never actually defined anywhere —
 * which is exactly why the builder modal rendered unstyled/broken. Only
 * genuinely new UI that has no existing equivalent (the template
 * gallery cards, the shortcode tag, the live-preview panel) gets its
 * own small, clearly-scoped CSS in renderStyles().
 *
 * All interactivity (toggles, modal, live preview, save/delete/copy) is
 * handled by assets/js/templates-admin.js — this class only renders markup
 * and bootstrap data.
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
class TemplatesPage extends AbstractPage
{
    public function title(): string
    {
        return __('قالب‌ها', 'campaignchi');
    }

    public function render(): void
    {
        $app      = Application::getInstance();
        $settings = $app->make(SliderSettingsService::class);
        $sliders  = $app->make(SliderRepository::class);

        $enabledTemplates = $settings->getEnabledTemplates();
        $allSliders       = $sliders->all();
?>
        <div class="cmc-row cmc-row--between cmc-mb-5">
            <div>
                <h2 style="font-size:var(--cmc-font-size-xl);font-weight:700;color:var(--cmc-text-heading);margin:0">
                    <?php esc_html_e('قالب‌ها', 'campaignchi'); ?>
                </h2>
                <p style="color:var(--cmc-text-muted);font-size:var(--cmc-font-size-sm);margin:4px 0 0">
                    <?php esc_html_e('یکی از ۵ قالب اسلایدر کمپین را انتخاب کنید، آن را شخصی‌سازی کنید و یک شورت‌کد یا ویجت المنتور آماده دریافت کنید.', 'campaignchi'); ?>
                </p>
            </div>
        </div>

        <?php $this->renderGallery($enabledTemplates); ?>

        <div class="cmc-alert cmc-alert--info cmc-mb-5">
            <i class="ti ti-info-circle cmc-alert__icon"></i>
            <div class="cmc-alert__body">
                <?php esc_html_e('هر اسلایدر ذخیره‌شده در جدول زیر، در ویجت «اسلایدر کمپین کمپین‌چی» داخل المنتور هم به‌صورت یک گزینه‌ی «استفاده از پریست» قابل انتخاب است.', 'campaignchi'); ?>
            </div>
        </div>

        <?php $this->renderSlidersTable($allSliders); ?>

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
                            <label class="cmc-toggle cmc-toggle--sm" title="<?php esc_attr_e('فعال/غیرفعال در انتخابگرها', 'campaignchi'); ?>">
                                <input type="checkbox"
                                    class="cmc-toggle__input cmc-template-enable-toggle"
                                    data-template="<?php echo esc_attr($template->id()); ?>"
                                    <?php checked($isEnabled); ?>>
                                <div class="cmc-toggle__track">
                                    <div class="cmc-toggle__thumb"></div>
                                </div>
                            </label>
                        </div>
                        <p><?php echo esc_html($template->description()); ?></p>

                        <button type="button"
                            class="cmc-btn cmc-btn--secondary cmc-btn--sm cmc-template-use-btn"
                            data-template="<?php echo esc_attr($template->id()); ?>"
                            style="width:100%"
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
        <div class="cmc-card cmc-card--flush">
            <div class="cmc-card__header" style="padding:var(--cmc-space-5) var(--cmc-space-5) 0;">
                <div class="cmc-card__title"><?php esc_html_e('اسلایدرهای ذخیره‌شده', 'campaignchi'); ?></div>
            </div>

            <?php if (empty($sliders)): ?>
                <div class="cmc-empty">
                    <div class="cmc-empty__icon"><i class="ti ti-carousel-horizontal"></i></div>
                    <div class="cmc-empty__title"><?php esc_html_e('هنوز هیچ اسلایدری نساخته‌اید', 'campaignchi'); ?></div>
                    <div class="cmc-empty__desc"><?php esc_html_e('از یکی از قالب‌های بالا شروع کنید.', 'campaignchi'); ?></div>
                </div>
            <?php else: ?>
                <div class="cmc-table-wrap">
                    <table class="cmc-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('عنوان', 'campaignchi'); ?></th>
                                <th><?php esc_html_e('قالب', 'campaignchi'); ?></th>
                                <th><?php esc_html_e('شورت‌کد', 'campaignchi'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sliders as $slider): ?>
                                <?php
                                $template  = TemplateRegistry::get($slider['template']);
                                $shortcode = sprintf('[%s id="%d"]', CampaignSliderShortcode::TAG, $slider['id']);
                                ?>
                                <tr>
                                    <td class="cmc-table__cell--bold"><?php echo esc_html($slider['title']); ?></td>
                                    <td>
                                        <span class="cmc-badge cmc-badge--primary"><?php echo esc_html($template ? $template->label() : $slider['template']); ?></span>
                                    </td>
                                    <td>
                                        <code class="cmc-shortcode-tag"><?php echo esc_html($shortcode); ?></code>
                                        <button type="button" class="cmc-btn cmc-btn--ghost cmc-btn--icon cmc-btn--sm cmc-slider-copy-btn" data-shortcode="<?php echo esc_attr($shortcode); ?>" title="<?php esc_attr_e('کپی شورت‌کد', 'campaignchi'); ?>">
                                            <i class="ti ti-copy"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <div class="cmc-table__row-actions">
                                            <button type="button" class="cmc-btn cmc-btn--ghost cmc-btn--icon cmc-btn--sm cmc-slider-edit-btn" data-id="<?php echo esc_attr((string) $slider['id']); ?>" title="<?php esc_attr_e('ویرایش', 'campaignchi'); ?>">
                                                <i class="ti ti-pencil"></i>
                                            </button>
                                            <button type="button" class="cmc-btn cmc-btn--ghost cmc-btn--icon cmc-btn--sm cmc-slider-delete-btn" data-id="<?php echo esc_attr((string) $slider['id']); ?>" title="<?php esc_attr_e('حذف', 'campaignchi'); ?>">
                                                <i class="ti ti-trash" style="color:var(--cmc-danger)"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Builder modal — rebuilt on top of the REAL .cmc-modal-overlay /
     * .cmc-modal structure (see components.css). The form is a single
     * vertical stack using .cmc-form-group/.cmc-label/.cmc-input/
     * .cmc-select + real .cmc-toggle switches; the live preview is now a
     * full-width horizontal section BELOW the form (not a side column),
     * per explicit feedback that the previous side-by-side layout looked
     * broken.
     */
    private function renderBuilderModal(): void
    {
    ?>
        <div class="cmc-modal-overlay" id="cmc-slider-builder-modal">
            <div class="cmc-modal cmc-modal--wide">
                <div class="cmc-modal__header">
                    <span class="cmc-modal__title"><?php esc_html_e('ساخت / ویرایش اسلایدر', 'campaignchi'); ?></span>
                    <button type="button" class="cmc-modal__close" id="cmc-slider-modal-close-btn"><i class="ti ti-x"></i></button>
                </div>

                <div class="cmc-modal__body">

                    <div class="cmc-form-group cmc-mb-4">
                        <label class="cmc-label cmc-label--required" for="cmc-f-title"><?php esc_html_e('عنوان اسلایدر', 'campaignchi'); ?></label>
                        <input type="text" id="cmc-f-title" class="cmc-input" placeholder="<?php esc_attr_e('مثلاً: اسلایدر فلش‌سیل صفحه اصلی', 'campaignchi'); ?>">
                    </div>

                    <div class="cmc-grid cmc-grid--3 cmc-mb-4">
                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-f-template"><?php esc_html_e('قالب', 'campaignchi'); ?></label>
                            <select id="cmc-f-template" class="cmc-select">
                                <?php foreach (TemplateRegistry::all() as $template): ?>
                                    <option value="<?php echo esc_attr($template->id()); ?>"><?php echo esc_html($template->label()); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-f-campaign"><?php esc_html_e('کمپین', 'campaignchi'); ?></label>
                            <select id="cmc-f-campaign" class="cmc-select">
                                <option value="0"><?php esc_html_e('— انتخاب خودکار (بالاترین اولویت) —', 'campaignchi'); ?></option>
                            </select>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-f-order"><?php esc_html_e('ترتیب نمایش', 'campaignchi'); ?></label>
                            <select id="cmc-f-order" class="cmc-select">
                                <option value="priority"><?php esc_html_e('اولویت پیش‌فرض کمپین', 'campaignchi'); ?></option>
                                <option value="newest"><?php esc_html_e('جدیدترین', 'campaignchi'); ?></option>
                                <option value="random"><?php esc_html_e('تصادفی', 'campaignchi'); ?></option>
                            </select>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-f-limit"><?php esc_html_e('تعداد محصولات', 'campaignchi'); ?></label>
                            <input type="number" id="cmc-f-limit" class="cmc-input" min="1" max="20" value="8">
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-f-autoplay-speed"><?php esc_html_e('سرعت پخش خودکار (ms)', 'campaignchi'); ?></label>
                            <input type="number" id="cmc-f-autoplay-speed" class="cmc-input" min="1000" max="15000" step="500" value="4000">
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-f-radius"><?php esc_html_e('گردی گوشه‌ها', 'campaignchi'); ?></label>
                            <input type="number" id="cmc-f-radius" class="cmc-input" min="0" max="40" value="16">
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-f-primary-color"><?php esc_html_e('رنگ اصلی', 'campaignchi'); ?></label>
                            <?php $this->renderColorField('cmc-f-primary-color', '#6C47FF'); ?>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-f-accent-color"><?php esc_html_e('رنگ تاکیدی', 'campaignchi'); ?></label>
                            <?php $this->renderColorField('cmc-f-accent-color', '#FF6B35'); ?>
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label" for="cmc-f-cta-text"><?php esc_html_e('متن دکمه CTA', 'campaignchi'); ?></label>
                            <input type="text" id="cmc-f-cta-text" class="cmc-input" placeholder="<?php esc_attr_e('مشاهده محصول', 'campaignchi'); ?>">
                        </div>

                        <div class="cmc-form-group" style="grid-column:span 2">
                            <label class="cmc-label" for="cmc-f-badge-text"><?php esc_html_e('متن بج تخفیف (دلخواه)', 'campaignchi'); ?></label>
                            <input type="text" id="cmc-f-badge-text" class="cmc-input" placeholder="<?php esc_attr_e('خالی = درصد خودکار', 'campaignchi'); ?>">
                        </div>

                        <!-- ⚠️ NEW: per-slider override for the header's campaign-type
                             badge text (e.g. "فلش سیل" / "پیشنهاد شگفت‌انگیز"). Empty =
                             inherit from the global Appearance default / campaign type. -->
                        <div class="cmc-form-group" style="grid-column:span 2">
                            <label class="cmc-label" for="cmc-f-type-badge-text"><?php esc_html_e('متن بج نوع کمپین (دلخواه)', 'campaignchi'); ?></label>
                            <input type="text" id="cmc-f-type-badge-text" class="cmc-input" placeholder="<?php esc_attr_e('خالی = نام نوع کمپین به‌صورت خودکار', 'campaignchi'); ?>">
                        </div>
                    </div>

                    <hr class="cmc-divider">

                    <div class="cmc-grid cmc-grid--3 cmc-mb-4">
                        <?php $this->renderToggleField('cmc-f-autoplay', __('پخش خودکار', 'campaignchi'), true); ?>
                        <?php $this->renderToggleField('cmc-f-loop', __('چرخه پیوسته', 'campaignchi'), true); ?>
                        <?php $this->renderToggleField('cmc-f-arrows', __('فلش‌های ناوبری', 'campaignchi'), true); ?>
                        <?php $this->renderToggleField('cmc-f-dots', __('نقاط ناوبری', 'campaignchi'), true); ?>
                        <?php $this->renderToggleField('cmc-f-show-countdown', __('شمارش معکوس', 'campaignchi'), true); ?>
                        <?php $this->renderToggleField('cmc-f-show-stock', __('نوار موجودی', 'campaignchi'), true); ?>
                        <?php $this->renderToggleField('cmc-f-dark-mode', __('حالت تیره', 'campaignchi'), false); ?>
                    </div>

                    <hr class="cmc-divider">

                    <!-- ⚠️ Live preview: a full-width horizontal section BELOW
                         the form, not a side column — per explicit feedback
                         that the previous 2-column layout looked broken. -->
                    <div class="cmc-builder-preview-section">
                        <div class="cmc-builder-preview-section__label">
                            <i class="ti ti-eye"></i>
                            <?php esc_html_e('پیش‌نمایش زنده', 'campaignchi'); ?>
                        </div>
                        <div id="cmc-slider-preview-pane" class="cmc-builder-preview-section__pane"></div>
                    </div>

                </div>

                <div class="cmc-modal__footer">
                    <button type="button" class="cmc-btn cmc-btn--secondary" id="cmc-slider-cancel-btn"><?php esc_html_e('انصراف', 'campaignchi'); ?></button>
                    <button type="button" class="cmc-btn cmc-btn--primary" id="cmc-slider-save-btn">
                        <i class="ti ti-device-floppy"></i>
                        <?php esc_html_e('ذخیره اسلایدر', 'campaignchi'); ?>
                    </button>
                </div>
            </div>
        </div>
    <?php
    }

    /** A labeled real .cmc-toggle switch, used for every behavior flag in the builder form. */
    private function renderToggleField(string $id, string $label, bool $checkedByDefault): void
    {
    ?>
        <label class="cmc-toggle">
            <input type="checkbox" class="cmc-toggle__input" id="<?php echo esc_attr($id); ?>" <?php checked($checkedByDefault); ?>>
            <div class="cmc-toggle__track">
                <div class="cmc-toggle__thumb"></div>
            </div>
            <span class="cmc-toggle__label"><?php echo esc_html($label); ?></span>
        </label>
    <?php
    }

    /**
     * Render a single, unified "input with an embedded color trigger"
     * (.cmc-color-field component, defined in components.css) — shared
     * with AppearancePage so both screens present color pickers
     * identically. The native <input type="color"> keeps the given $id
     * unchanged, so existing JS (templates-admin.js's
     * collectFormValues/resetForm/fillForm) that reads/writes its value
     * directly by id keeps working unchanged.
     */
    private function renderColorField(string $id, string $value): void
    {
        $value = $this->sanitizeHexForDisplay($value);
    ?>
        <div class="cmc-color-field">
            <input type="text" class="cmc-color-field__hex" value="<?php echo esc_attr($value); ?>" maxlength="7" spellcheck="false" aria-label="<?php esc_attr_e('کد رنگ', 'campaignchi'); ?>">
            <label class="cmc-color-field__swatch" style="background:<?php echo esc_attr($value); ?>">
                <input type="color" id="<?php echo esc_attr($id); ?>" class="cmc-color-field__input" value="<?php echo esc_attr($value); ?>">
            </label>
        </div>
    <?php
    }

    /** Defensive fallback so a corrupted/unexpected value never breaks the swatch's inline style attribute. */
    private function sanitizeHexForDisplay(string $value): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '#000000';
    }

    /**
     * Only genuinely NEW UI (no existing equivalent in base.css/components.css)
     * gets its own CSS here: the template gallery cards, the shortcode tag,
     * the modal width modifier, and the full-width live-preview section.
     */
    private function renderStyles(): void
    {
    ?>
        <style>
            .cmc-template-gallery {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
                gap: 18px;
                margin: 0 0 28px;
            }

            .cmc-template-card {
                border: 1px solid var(--cmc-border);
                border-radius: var(--cmc-radius-lg);
                overflow: hidden;
                background: var(--cmc-surface);
                transition: opacity 150ms ease;
            }

            .cmc-template-card.is-disabled {
                opacity: .5;
            }

            .cmc-template-card__swatch {
                height: 90px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 30px;
                color: #fff;
            }

            .cmc-template-card__body {
                padding: var(--cmc-space-4) var(--cmc-space-4) var(--cmc-space-4);
                display: flex;
                flex-direction: column;
                gap: 8px;
            }

            .cmc-template-card__head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
            }

            .cmc-template-card__head h3 {
                font-size: var(--cmc-font-size-md);
                font-weight: 700;
                color: var(--cmc-text-heading);
                margin: 0;
            }

            .cmc-template-card__body p {
                font-size: var(--cmc-font-size-sm);
                color: var(--cmc-text-muted);
                line-height: 1.7;
                min-height: 56px;
                margin: 0;
            }

            .cmc-toggle--sm .cmc-toggle__track {
                width: 32px;
                height: 18px;
            }

            .cmc-toggle--sm .cmc-toggle__thumb {
                width: 12px;
                height: 12px;
            }

            .cmc-toggle--sm .cmc-toggle__input:checked+.cmc-toggle__track .cmc-toggle__thumb {
                transform: translateX(-14px);
            }

            .cmc-shortcode-tag {
                background: var(--cmc-surface-2);
                padding: 4px 8px;
                border-radius: 6px;
                font-size: 12px;
                direction: ltr;
                display: inline-block;
                color: var(--cmc-text-heading);
            }

            .cmc-modal--wide {
                max-width: 880px;
            }

            .cmc-builder-preview-section__label {
                display: flex;
                align-items: center;
                gap: 6px;
                color: var(--cmc-text-muted);
                font-size: var(--cmc-font-size-sm);
                font-weight: 600;
                margin-bottom: 10px;
            }

            .cmc-builder-preview-section__pane {
                background: #11151c;
                border-radius: var(--cmc-radius-lg);
                padding: 18px;
                min-height: 240px;
                overflow-x: auto;
            }
        </style>
<?php
    }
}
