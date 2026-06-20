/**
 * Campaignchi — admin "Reports" page controller.
 *
 * The report itself is fully server-rendered; this script only handles the
 * custom date-range interaction:
 *   - initializes the shared Jalali date pickers (CMCDatePicker),
 *   - pre-fills them with the currently-active custom range (edit mode),
 *   - toggles the custom-range row open,
 *   - and navigates to the report URL with the chosen from/to dates.
 *
 * Preset ranges are plain links (no JS needed) — see ReportsPage::renderRangeBar().
 */
(function (window, document) {
    'use strict';

    function $(id) {
        return document.getElementById(id);
    }

    /** Reveal the custom-range row and scroll it into view. */
    function openCustomRow() {
        var row = $('cmc-report-custom-row');
        if (row) {
            row.style.display = 'flex';
            row.style.marginTop = 'var(--cmc-space-4)';
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    /** Build the report URL for a custom range and navigate to it. */
    function applyCustomRange() {
        var fromEl = $('cmc-report-from');
        var toEl = $('cmc-report-to');
        var baseEl = $('cmc-report-base');

        var from = fromEl ? fromEl.value : '';
        var to = toEl ? toEl.value : '';

        if (!from || !to) {
            if (window.CMC && typeof CMC.toast === 'function') {
                CMC.toast('هر دو تاریخ شروع و پایان را انتخاب کنید.', 'warning');
            }
            return;
        }

        var base = baseEl ? baseEl.value : '';
        var url = base
            + '&range=custom'
            + '&from=' + encodeURIComponent(from.substring(0, 10))
            + '&to=' + encodeURIComponent(to.substring(0, 10));

        window.location.href = url;
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Initialize the shared Jalali date pickers (idempotent — guarded internally).
        if (typeof CMCDatePicker !== 'undefined') {
            CMCDatePicker.init();

            // Pre-fill the pickers when re-opening an existing custom range,
            // so the display inputs show the active dates instead of being blank.
            if (window.CMC_REPORT && window.CMC_REPORT.isCustom) {
                if (window.CMC_REPORT.from) {
                    CMCDatePicker.setValue('cmc-report-from', window.CMC_REPORT.from);
                }
                if (window.CMC_REPORT.to) {
                    CMCDatePicker.setValue('cmc-report-to', window.CMC_REPORT.to);
                }
            }
        }

        var toggleBtn = $('cmc-report-custom-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', openCustomRow);
        }

        var applyBtn = $('cmc-report-apply');
        if (applyBtn) {
            applyBtn.addEventListener('click', applyCustomRange);
        }
    });
})(window, document);
