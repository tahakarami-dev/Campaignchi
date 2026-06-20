/**
 * Campaignchi — admin "Reports" page controller.
 *
 * The Reports page is fully AJAX-driven: PHP renders only the shell
 * (header + range bar) plus empty regions; this script fills every region
 * with server-rendered HTML fragments fetched via cmc_get_report_data,
 * showing skeleton placeholders meanwhile. Preset changes and custom
 * ranges both re-fetch without a full page reload, and keep the URL and
 * the CSV export link in sync.
 */
(function (window, document) {
    'use strict';

    function $(id) {
        return document.getElementById(id);
    }

    /* ---------------------------------------------------------------- */
    /* Skeleton templates (shown while a region is loading)             */
    /* ---------------------------------------------------------------- */

    function kpiSkeleton() {
        var card = '<div class="cmc-skel cmc-skel-card"></div>';
        return card + card + card + card;
    }

    function chartSkeleton() {
        return '<div class="cmc-skel cmc-skel-chart"></div>';
    }

    function tableSkeleton(rows) {
        var html = '<div style="padding:var(--cmc-space-4) 0">';
        for (var i = 0; i < rows; i++) {
            html += '<div class="cmc-skel cmc-skel-line" style="width:' + (60 + (i % 4) * 10) + '%"></div>';
        }
        return html + '</div>';
    }

    function showSkeletons() {
        $('cmc-report-kpis').innerHTML = kpiSkeleton();
        $('cmc-report-chart').innerHTML = chartSkeleton();
        $('cmc-report-campaigns').innerHTML = tableSkeleton(5);
        $('cmc-report-products').innerHTML = tableSkeleton(4);
    }

    /* ---------------------------------------------------------------- */
    /* Data fetch + render                                              */
    /* ---------------------------------------------------------------- */

    function fetchReport(rangeKey, from, to, pushHistory) {
        showSkeletons();

        var params = { range: rangeKey };
        if (rangeKey === 'custom') {
            params.from = (from || '').substring(0, 10);
            params.to = (to || '').substring(0, 10);
        }

        // CMC.ajax() always POSTs (FormData) with the shared nonce.
        CMC.ajax('cmc_get_report_data', params)
            .then(function (res) {
                $('cmc-report-kpis').innerHTML = res.kpis;
                $('cmc-report-chart').innerHTML = res.chart;
                $('cmc-report-campaigns').innerHTML = res.campaigns;
                $('cmc-report-products').innerHTML = res.products;

                var label = $('cmc-report-range-label');
                if (label) {
                    label.textContent = res.range_label;
                }

                var exportBtn = $('cmc-report-export');
                if (exportBtn) {
                    exportBtn.href = res.export_url;
                }

                setActivePreset(res.range_key);

                if (pushHistory) {
                    updateUrl(res.range_key, params.from, params.to);
                }
            })
            .catch(function () {
                if (window.CMC && typeof CMC.toast === 'function') {
                    CMC.toast('خطا در بارگذاری گزارش.', 'danger');
                }
                $('cmc-report-chart').innerHTML =
                    '<div style="padding:40px;text-align:center;color:var(--cmc-danger)">خطا در بارگذاری گزارش.</div>';
            });
    }

    function setActivePreset(rangeKey) {
        document.querySelectorAll('.cmc-report-preset').forEach(function (btn) {
            var active = btn.dataset.range === rangeKey;
            btn.classList.toggle('cmc-btn--primary', active);
            btn.classList.toggle('cmc-btn--secondary', !active);
        });
    }

    function updateUrl(rangeKey, from, to) {
        var base = $('cmc-report-base');
        if (!base || !window.history || !window.history.pushState) {
            return;
        }

        var url = base.value + '&range=' + encodeURIComponent(rangeKey);
        if (rangeKey === 'custom' && from && to) {
            url += '&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
        }

        window.history.pushState({ range: rangeKey }, '', url);
    }

    /* ---------------------------------------------------------------- */
    /* Custom range UI                                                  */
    /* ---------------------------------------------------------------- */

    function openCustomRow() {
        var row = $('cmc-report-custom-row');
        if (row) {
            row.style.display = 'flex';
            row.style.marginTop = 'var(--cmc-space-4)';
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function applyCustomRange() {
        var fromEl = $('cmc-report-from');
        var toEl = $('cmc-report-to');

        var from = fromEl ? fromEl.value : '';
        var to = toEl ? toEl.value : '';

        if (!from || !to) {
            if (window.CMC && typeof CMC.toast === 'function') {
                CMC.toast('هر دو تاریخ شروع و پایان را انتخاب کنید.', 'warning');
            }
            return;
        }

        fetchReport('custom', from, to, true);
    }

    /* ---------------------------------------------------------------- */
    /* Init                                                             */
    /* ---------------------------------------------------------------- */

    document.addEventListener('DOMContentLoaded', function () {
        // Jalali date pickers for the custom-range inputs (idempotent).
        if (typeof CMCDatePicker !== 'undefined') {
            CMCDatePicker.init();

            if (window.CMC_REPORT && window.CMC_REPORT.isCustom) {
                if (window.CMC_REPORT.from) {
                    CMCDatePicker.setValue('cmc-report-from', window.CMC_REPORT.from + 'T00:00');
                }
                if (window.CMC_REPORT.to) {
                    CMCDatePicker.setValue('cmc-report-to', window.CMC_REPORT.to + 'T00:00');
                }
            }
        }

        // Preset buttons.
        document.querySelectorAll('.cmc-report-preset').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var range = btn.dataset.range;

                if (range === 'custom') {
                    setActivePreset('custom');
                    openCustomRow();
                    return; // wait for the user to apply concrete dates
                }

                fetchReport(range, '', '', true);
            });
        });

        var applyBtn = $('cmc-report-apply');
        if (applyBtn) {
            applyBtn.addEventListener('click', applyCustomRange);
        }

        // Initial load — render the range that PHP resolved on first paint.
        var initial = window.CMC_REPORT || { range: 'last7', from: '', to: '' };
        fetchReport(initial.range, initial.from, initial.to, false);
    });
})(window, document);