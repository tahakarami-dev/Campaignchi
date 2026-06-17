/**
 * Campaignchi — admin "Templates" page controller.
 *
 * Handles: template gallery enable/disable toggles, the slider builder
 * modal (create/edit), a debounced WYSIWYG live preview (reusing the
 * exact same frontend-slider.js engine used on the public site), and the
 * saved-sliders table actions (edit / delete / copy-shortcode).
 *
 * Expects `window.CMC_TEMPLATES_DATA = { enabledTemplates: [...] }` to
 * have been printed inline by TemplatesPage.php before this file loads.
 */
(function (window, document) {
    'use strict';

    var state = {
        editingId: null,
        campaignsLoaded: false,
        previewTimer: null,
    };

    var FIELD_IDS = [
        'cmc-f-title', 'cmc-f-template', 'cmc-f-campaign', 'cmc-f-limit', 'cmc-f-order',
        'cmc-f-autoplay', 'cmc-f-autoplay-speed', 'cmc-f-loop', 'cmc-f-arrows', 'cmc-f-dots',
        'cmc-f-show-countdown', 'cmc-f-show-stock', 'cmc-f-primary-color', 'cmc-f-accent-color',
        'cmc-f-radius', 'cmc-f-dark-mode', 'cmc-f-cta-text', 'cmc-f-badge-text',
    ];

    function $(id) {
        return document.getElementById(id);
    }

    /* ---------------------------------------------------------------- */
    /* Template gallery: enable / disable toggle                        */
    /* ---------------------------------------------------------------- */

    function toggleTemplateEnabled(templateId, enabled, toggleEl) {
        CMC.ajax('cmc_toggle_template_enabled', { template_id: templateId, enabled: enabled ? '1' : '0' })
            .then(function (res) {
                window.CMC_TEMPLATES_DATA.enabledTemplates = res.enabled_templates;
                applyEnabledState(templateId, enabled);
                CMC.toast(enabled ? 'قالب فعال شد.' : 'قالب غیرفعال شد.', 'success');
            })
            .catch(function () {
                toggleEl.checked = !enabled; // revert on failure
                CMC.toast('خطا در به‌روزرسانی وضعیت قالب.', 'error');
            });
    }

    function applyEnabledState(templateId, enabled) {
        var card = document.querySelector('.cmc-template-card[data-template="' + templateId + '"]');
        if (card) {
            card.classList.toggle('is-disabled', !enabled);
            var useBtn = card.querySelector('.cmc-template-use-btn');
            if (useBtn) {
                useBtn.disabled = !enabled;
            }
        }

        var option = document.querySelector('#cmc-f-template option[value="' + templateId + '"]');
        if (option) {
            option.disabled = !enabled;
        }
    }

    /* ---------------------------------------------------------------- */
    /* Campaign picker (lazy-loaded once)                               */
    /* ---------------------------------------------------------------- */

    function ensureCampaignsLoaded() {
        if (state.campaignsLoaded) {
            return Promise.resolve();
        }

        return CMC.ajax('cmc_get_campaigns_for_picker', {}).then(function (res) {
            var select = $('cmc-f-campaign');
            select.innerHTML = '<option value="0">— انتخاب خودکار (بالاترین اولویت) —</option>';

            res.campaigns.forEach(function (campaign) {
                var option = document.createElement('option');
                option.value = campaign.id;
                option.textContent = campaign.label;
                select.appendChild(option);
            });

            state.campaignsLoaded = true;
        });
    }

    /* ---------------------------------------------------------------- */
    /* Builder modal: open / reset / collect / save                     */
    /* ---------------------------------------------------------------- */

    function resetForm() {
        $('cmc-f-title').value = '';
        $('cmc-f-campaign').value = '0';
        $('cmc-f-limit').value = '8';
        $('cmc-f-order').value = 'priority';
        $('cmc-f-autoplay').checked = true;
        $('cmc-f-autoplay-speed').value = '4000';
        $('cmc-f-loop').checked = true;
        $('cmc-f-arrows').checked = true;
        $('cmc-f-dots').checked = true;
        $('cmc-f-show-countdown').checked = true;
        $('cmc-f-show-stock').checked = true;
        $('cmc-f-primary-color').value = '#6C47FF';
        $('cmc-f-accent-color').value = '#FF6B35';
        $('cmc-f-radius').value = '16';
        $('cmc-f-dark-mode').checked = false;
        $('cmc-f-cta-text').value = '';
        $('cmc-f-badge-text').value = '';
    }

    function fillForm(preset) {
        var s = preset.settings || {};

        $('cmc-f-title').value = preset.title || '';
        $('cmc-f-template').value = preset.template;
        $('cmc-f-campaign').value = preset.campaign_id || '0';
        $('cmc-f-limit').value = s.limit || 8;
        $('cmc-f-order').value = s.order || 'priority';
        $('cmc-f-autoplay').checked = s.autoplay !== false;
        $('cmc-f-autoplay-speed').value = s.autoplay_speed || 4000;
        $('cmc-f-loop').checked = s.loop !== false;
        $('cmc-f-arrows').checked = s.arrows !== false;
        $('cmc-f-dots').checked = s.dots !== false;
        $('cmc-f-show-countdown').checked = s.show_countdown !== false;
        $('cmc-f-show-stock').checked = s.show_stock !== false;
        $('cmc-f-primary-color').value = s.primary_color || '#6C47FF';
        $('cmc-f-accent-color').value = s.accent_color || '#FF6B35';
        $('cmc-f-radius').value = s.radius || 16;
        $('cmc-f-dark-mode').checked = !!s.dark_mode;
        $('cmc-f-cta-text').value = s.cta_text || '';
        $('cmc-f-badge-text').value = s.badge_text || '';
    }

    function collectFormValues() {
        return {
            title: $('cmc-f-title').value,
            template: $('cmc-f-template').value,
            campaign_id: $('cmc-f-campaign').value,
            limit: $('cmc-f-limit').value,
            order: $('cmc-f-order').value,
            autoplay: $('cmc-f-autoplay').checked ? '1' : '0',
            autoplay_speed: $('cmc-f-autoplay-speed').value,
            loop: $('cmc-f-loop').checked ? '1' : '0',
            arrows: $('cmc-f-arrows').checked ? '1' : '0',
            dots: $('cmc-f-dots').checked ? '1' : '0',
            show_countdown: $('cmc-f-show-countdown').checked ? '1' : '0',
            show_stock: $('cmc-f-show-stock').checked ? '1' : '0',
            primary_color: $('cmc-f-primary-color').value,
            accent_color: $('cmc-f-accent-color').value,
            radius: $('cmc-f-radius').value,
            dark_mode: $('cmc-f-dark-mode').checked ? '1' : '0',
            cta_text: $('cmc-f-cta-text').value,
            badge_text: $('cmc-f-badge-text').value,
        };
    }

    function openModal(templateId, preset) {
        ensureCampaignsLoaded().then(function () {
            resetForm();
            state.editingId = preset ? preset.id : null;

            if (preset) {
                fillForm(preset);
            } else if (templateId) {
                $('cmc-f-template').value = templateId;
            }

            $('cmc-slider-builder-modal').classList.add('is-open');
            CMC.modal.open('cmc-slider-builder-modal');
            requestPreview();
        });
    }

    function closeModal() {
        if (state.previewTimer) {
            clearTimeout(state.previewTimer);
        }

        var pane = $('cmc-slider-preview-pane');
        if (pane && window.CMCSlider) {
            window.CMCSlider.destroyAll(pane);
        }

        CMC.modal.close('cmc-slider-builder-modal');
        document.getElementById('cmc-slider-builder-modal').classList.remove('is-open');
    }

    /* ---------------------------------------------------------------- */
    /* Live preview (debounced)                                         */
    /* ---------------------------------------------------------------- */

    function requestPreview() {
        if (state.previewTimer) {
            clearTimeout(state.previewTimer);
        }

        state.previewTimer = setTimeout(doPreview, 400);
    }

    function doPreview() {
        var pane = $('cmc-slider-preview-pane');
        var values = collectFormValues();

        CMC.ajax('cmc_preview_slider', values)
            .then(function (res) {
                if (window.CMCSlider) {
                    window.CMCSlider.destroyAll(pane);
                }

                pane.innerHTML = res.html;

                if (window.CMCSlider) {
                    window.CMCSlider.initAll(pane);
                }
            })
            .catch(function () {
                pane.innerHTML = '<div style="padding:40px;text-align:center;color:#e5484d;">خطا در بارگذاری پیش‌نمایش.</div>';
            });
    }

    function bindLivePreviewInputs() {
        FIELD_IDS.forEach(function (id) {
            var el = $(id);
            if (!el) {
                return;
            }

            var eventName = (el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'color')
                ? 'change'
                : 'input';

            el.addEventListener(eventName, requestPreview);
        });
    }

    /* ---------------------------------------------------------------- */
    /* Save / update / delete / copy                                    */
    /* ---------------------------------------------------------------- */

    function saveSlider() {
        var values = collectFormValues();

        if (!values.title.trim()) {
            CMC.toast('عنوان اسلایدر الزامی است.', 'error');
            return;
        }

        var action = state.editingId ? 'cmc_update_slider' : 'cmc_save_slider';
        if (state.editingId) {
            values.id = state.editingId;
        }

        CMC.ajax(action, values)
            .then(function (res) {
                CMC.toast('اسلایدر با موفقیت ذخیره شد.', 'success');
                closeModal();
                refreshTable();
            })
            .catch(function () {
                CMC.toast('خطا در ذخیره‌سازی اسلایدر.', 'error');
            });
    }

    function deleteSlider(id) {
        CMC.confirm('آیا از حذف این اسلایدر مطمئن هستید؟', function () {
            CMC.ajax('cmc_delete_slider', { id: id }).then(function () {
                CMC.toast('اسلایدر حذف شد.', 'success');
                refreshTable();
            });
        });
    }

    function editSlider(id) {
        CMC.ajax('cmc_get_slider', { id: id })
            .then(function (res) {
                openModal(null, res.slider);
            })
            .catch(function () {
                CMC.toast('اسلایدر پیدا نشد یا خطایی رخ داد.', 'error');
            });
    }

    function copyShortcode(shortcode) {
        navigator.clipboard.writeText(shortcode).then(function () {
            CMC.toast('شورت‌کد کپی شد.', 'success');
        });
    }

    /**
     * Table-mutating actions (save/update/delete) all end with a full
     * page reload — the PHP-rendered table is the single source of
     * truth, so this avoids re-implementing its render logic in JS.
     */
    function refreshTable() {
        window.location.reload();
    }

    /* ---------------------------------------------------------------- */
    /* Init                                                              */
    /* ---------------------------------------------------------------- */

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.cmc-template-enable-toggle').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                toggleTemplateEnabled(toggle.dataset.template, toggle.checked, toggle);
            });
        });

        document.querySelectorAll('.cmc-template-use-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(btn.dataset.template, null);
            });
        });

        document.querySelectorAll('.cmc-slider-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                editSlider(btn.dataset.id);
            });
        });

        document.querySelectorAll('.cmc-slider-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                deleteSlider(btn.dataset.id);
            });
        });

        document.querySelectorAll('.cmc-slider-copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                copyShortcode(btn.dataset.shortcode);
            });
        });

        var saveBtn = $('cmc-slider-save-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveSlider);
        }

        var cancelBtn = $('cmc-slider-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeModal);
        }

        var closeBtn = $('cmc-slider-modal-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }

        bindLivePreviewInputs();
    });
})(window, document);