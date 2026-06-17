/**
 * Campaignchi — admin "Appearance" page controller.
 *
 * Collects the global slider-defaults form and saves it via
 * cmc_save_global_slider_settings, with toast feedback. These values are
 * the global fallback layer in SliderSettingsService::resolve() — they
 * apply to every shortcode/widget instance that does not explicitly
 * override a given field.
 */
(function (window, document) {
    'use strict';

    function $(id) {
        return document.getElementById(id);
    }

    function collectValues() {
        return {
            master_enabled: $('cmc-a-master-enabled').checked ? '1' : '0',
            default_template: $('cmc-a-default-template').value,
            primary_color: $('cmc-a-primary-color').value,
            accent_color: $('cmc-a-accent-color').value,
            radius: $('cmc-a-radius').value,
            dark_mode: $('cmc-a-dark-mode').checked ? '1' : '0',
            limit: $('cmc-a-limit').value,
            order: $('cmc-a-order').value,
            autoplay: $('cmc-a-autoplay').checked ? '1' : '0',
            autoplay_speed: $('cmc-a-autoplay-speed').value,
            arrows: $('cmc-a-arrows').checked ? '1' : '0',
            dots: $('cmc-a-dots').checked ? '1' : '0',
            show_countdown: $('cmc-a-show-countdown').checked ? '1' : '0',
            show_stock: $('cmc-a-show-stock').checked ? '1' : '0',
            cta_text: $('cmc-a-cta-text').value,
            classic_badge_enabled: $('cmc-a-classic-badge-enabled').checked ? '1' : '0',
            classic_badge_bg_color: $('cmc-a-classic-badge-bg').value,
            classic_badge_text_color: $('cmc-a-classic-badge-text').value,
        };
    }

    function save() {
        CMC.ajax('cmc_save_global_slider_settings', collectValues())
            .then(function () {
                CMC.toast('تنظیمات ظاهر اسلایدر ذخیره شد.', 'success');
            })
            .catch(function () {
                CMC.toast('خطا در ذخیره‌سازی تنظیمات.', 'error');
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var saveBtn = $('cmc-a-save-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', save);
        }
    });
})(window, document);