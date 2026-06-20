<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

use Msi\Campaignchi\Admin\AdminRouter;
use Msi\Campaignchi\Analytics\Services\ReportService;
use Msi\Campaignchi\Core\Application;
use Msi\Campaignchi\Helpers\JalaliHelper;

/**
 * Reports Page
 *
 * Fully AJAX-driven: render() outputs only the shell (header, range bar)
 * and skeleton placeholders. reports-page.js then fetches the actual
 * figures via cmc_get_report_data and swaps each region's HTML in. Both
 * preset changes and custom ranges re-fetch via AJAX (no full reload).
 *
 * Every figure shown here is sourced from the accurate campaign-sales
 * event log via ReportService — see CampaignSalesRecorder.
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
class ReportsPage extends AbstractPage
{
    public function title(): string
    {
        return __('گزارش‌ها', 'campaignchi');
    }

    public function render(): void
    {
        $service = $this->reportService();

        $rangeKey = isset($_GET['range']) ? sanitize_key((string) $_GET['range']) : 'last7';
        $from     = isset($_GET['from'])  ? sanitize_text_field((string) $_GET['from']) : null;
        $to       = isset($_GET['to'])    ? sanitize_text_field((string) $_GET['to'])   : null;

        $range   = $service->resolveRange($rangeKey, $from, $to);
        $baseUrl = AdminRouter::url('reports');
?>
        <!-- ---- Page header + export ---- -->
        <div class="cmc-row cmc-row--between cmc-mb-5">
            <div>
                <h2 style="font-size:var(--cmc-font-size-xl);font-weight:700;color:var(--cmc-text-heading);margin:0">
                    <?php esc_html_e('گزارش‌ها', 'campaignchi'); ?>
                </h2>
                <p style="color:var(--cmc-text-muted);font-size:var(--cmc-font-size-sm);margin:4px 0 0">
                    <?php
                    printf(
                        /* translators: %s: resolved range label. */
                        esc_html__('گزارش فروش کمپین‌ها — %s', 'campaignchi'),
                        '<strong id="cmc-report-range-label">' . esc_html($range['label']) . '</strong>'
                    );
                    ?>
                </p>
            </div>
            <a href="#" id="cmc-report-export" class="cmc-btn cmc-btn--secondary">
                <i class="ti ti-file-spreadsheet"></i>
                <?php esc_html_e('خروجی اکسل (سفارش‌ها)', 'campaignchi'); ?>
            </a>
        </div>

        <!-- ---- Range selector ---- -->
        <?php $this->renderRangeBar($service, $range); ?>

        <!-- ---- KPI cards (skeleton until AJAX fills them) ---- -->
        <div class="cmc-grid cmc-grid--4 cmc-mb-5" id="cmc-report-kpis"></div>

        <!-- ---- Daily sales chart ---- -->
        <div class="cmc-card cmc-mb-5">
            <div class="cmc-card__header">
                <div>
                    <div class="cmc-card__title"><?php esc_html_e('روند فروش', 'campaignchi'); ?></div>
                    <div class="cmc-card__subtitle"><?php esc_html_e('فروش محصولات کمپین در بازه‌ی انتخابی (تومان)', 'campaignchi'); ?></div>
                </div>
            </div>
            <div id="cmc-report-chart"></div>
        </div>

        <!-- ---- Per-campaign breakdown ---- -->
        <div class="cmc-card cmc-card--flush cmc-mb-5">
            <div class="cmc-card__header" style="padding:var(--cmc-space-5) var(--cmc-space-5) 0">
                <div class="cmc-card__title"><?php esc_html_e('عملکرد کمپین‌ها', 'campaignchi'); ?></div>
            </div>
            <div id="cmc-report-campaigns"></div>
        </div>

        <!-- ---- Top products ---- -->
        <div class="cmc-card cmc-card--flush" style="margin-bottom: 30px !important;">
            <div class="cmc-card__header" style="padding:var(--cmc-space-5) var(--cmc-space-5) 0">
                <div class="cmc-card__title"><?php esc_html_e('پرفروش‌ترین محصولات', 'campaignchi'); ?></div>
            </div>
            <div id="cmc-report-products"></div>
        </div>

        <input type="hidden" id="cmc-report-base" value="<?php echo esc_attr($baseUrl); ?>">

        <script>
            window.CMC_REPORT = {
                range: <?php echo wp_json_encode($range['key']); ?>,
                from: <?php echo wp_json_encode($range['key'] === 'custom' ? $range['start'] : ''); ?>,
                to: <?php echo wp_json_encode($range['key'] === 'custom' ? $range['end'] : ''); ?>,
                isCustom: <?php echo wp_json_encode($range['key'] === 'custom'); ?>
            };
        </script>

        <?php $this->renderSkeletonStyles(); ?>
<?php
    }

    // -------------------------------------------------------
    // RANGE BAR
    // -------------------------------------------------------

    private function renderRangeBar(ReportService $service, array $range): void
    {
        $isCustom = $range['key'] === 'custom';
?>
        <div class="cmc-card cmc-mb-5" style="padding:var(--cmc-space-4)">
            <div class="cmc-row cmc-row--sm" style="flex-wrap:wrap;gap:var(--cmc-space-2)" id="cmc-report-presets">
                <?php foreach ($service->presets() as $key => $label): ?>
                    <button type="button"
                        class="cmc-btn cmc-btn--sm cmc-report-preset <?php echo $range['key'] === $key ? 'cmc-btn--primary' : 'cmc-btn--secondary'; ?>"
                        data-range="<?php echo esc_attr($key); ?>">
                        <?php if ($key === 'custom'): ?><i class="ti ti-calendar-event"></i><?php endif; ?>
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Custom Jalali range row -->
            <div id="cmc-report-custom-row"
                 class="cmc-row cmc-row--sm"
                 style="flex-wrap:wrap;gap:var(--cmc-space-3);margin-top:<?php echo $isCustom ? 'var(--cmc-space-4)' : '0'; ?>;display:<?php echo $isCustom ? 'flex' : 'none'; ?>">

                <div class="cmc-form-group" style="flex:1;min-width:170px">
                    <label class="cmc-label"><?php esc_html_e('از تاریخ', 'campaignchi'); ?></label>
                    <div class="cmc-input-wrap">
                        <i class="ti ti-calendar cmc-input-wrap__icon"></i>
                        <input type="text" class="cmc-input"
                            data-cmc-datepicker="cmc-report-from"
                            placeholder="<?php esc_attr_e('کلیک کنید...', 'campaignchi'); ?>" readonly>
                    </div>
                    <input type="hidden" id="cmc-report-from" value="<?php echo $isCustom ? esc_attr($range['start']) : ''; ?>">
                </div>

                <div class="cmc-form-group" style="flex:1;min-width:170px">
                    <label class="cmc-label"><?php esc_html_e('تا تاریخ', 'campaignchi'); ?></label>
                    <div class="cmc-input-wrap">
                        <i class="ti ti-calendar cmc-input-wrap__icon"></i>
                        <input type="text" class="cmc-input"
                            data-cmc-datepicker="cmc-report-to"
                            placeholder="<?php esc_attr_e('کلیک کنید...', 'campaignchi'); ?>" readonly>
                    </div>
                    <input type="hidden" id="cmc-report-to" value="<?php echo $isCustom ? esc_attr($range['end']) : ''; ?>">
                </div>

                <div class="cmc-form-group" style="justify-content:flex-end">
                    <label class="cmc-label" style="visibility:hidden">.</label>
                    <button type="button" class="cmc-btn cmc-btn--primary" id="cmc-report-apply">
                        <i class="ti ti-filter"></i>
                        <?php esc_html_e('اعمال بازه', 'campaignchi'); ?>
                    </button>
                </div>
            </div>
        </div>
<?php
    }

    // =========================================================
    // AJAX FRAGMENTS (called by ReportsAjaxController)
    // =========================================================

    /** @param array<string,mixed> $summary */
    public function renderKpisFragment(array $summary): string
    {
        ob_start();
        $this->renderStatCard(__('فروش کل', 'campaignchi'), $summary['revenue_abbr'], 'ti-cash', 'purple', $summary['revenue_full'] . ' ' . __('تومان', 'campaignchi'));
        $this->renderStatCard(__('تعداد سفارش', 'campaignchi'), JalaliHelper::toPersianNums((string) $summary['orders']), 'ti-shopping-bag', 'orange', __('سفارش شامل محصول کمپین', 'campaignchi'));
        $this->renderStatCard(__('بازدید', 'campaignchi'), $summary['impressions_label'], 'ti-eye', 'blue', __('بازدید محصولات کمپین', 'campaignchi'));
        $this->renderStatCard(__('نرخ تبدیل', 'campaignchi'), $summary['conversion_label'], 'ti-click', 'green', __('سفارش به ازای بازدید', 'campaignchi'));
        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string,mixed>> $series
     */
    public function renderChartFragment(array $series, bool $hasData): string
    {
        ob_start();

        if (!$hasData || empty($series)) {
            ?>
            <div class="cmc-empty" style="padding:var(--cmc-space-10) 0">
                <div class="cmc-empty__icon"><i class="ti ti-chart-bar-off"></i></div>
                <div class="cmc-empty__title"><?php esc_html_e('داده‌ای در این بازه ثبت نشده', 'campaignchi'); ?></div>
                <div class="cmc-empty__desc"><?php esc_html_e('در این بازه هیچ فروشی از محصولات کمپین ثبت نشده است. بازه‌ی دیگری را انتخاب کنید.', 'campaignchi'); ?></div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        // ⚠️ Bug #4: taller chart + generous top padding so the value
        // tooltip (which sits at top:-28px above each bar) is never clipped
        // by the card edge or the section above it.
        $minWidth = max(0, count($series) * 38);
        ?>
        <div style="overflow-x:auto;padding-top:34px">
            <div class="cmc-chart-bars" style="height:220px;min-width:<?php echo esc_attr((string) $minWidth); ?>px">
                <div class="cmc-chart-bars__grid">
                    <span></span><span></span><span></span><span></span>
                </div>
                <div class="cmc-chart-bars__bars">
                    <?php foreach ($series as $bar): ?>
                        <div class="cmc-chart-bar<?php echo !empty($bar['is_today']) ? ' is-today' : ''; ?>"
                             style="--h:<?php echo max(4, (int) $bar['percent']); ?>%"
                             data-label="<?php echo esc_attr($bar['label']); ?>"
                             data-val="<?php echo esc_attr($bar['value_label']); ?>"></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string,mixed>> $campaigns
     */
    public function renderCampaignsFragment(array $campaigns): string
    {
        ob_start();

        if (empty($campaigns)) {
            ?>
            <div class="cmc-empty" style="padding:var(--cmc-space-6) 0">
                <div class="cmc-empty__icon"><i class="ti ti-bolt-off"></i></div>
                <div class="cmc-empty__title"><?php esc_html_e('کمپینی در این بازه فروش نداشته', 'campaignchi'); ?></div>
            </div>
            <?php
            return (string) ob_get_clean();
        }
        ?>
        <div class="cmc-table-wrap">
            <table class="cmc-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('کمپین', 'campaignchi'); ?></th>
                        <th><?php esc_html_e('نوع', 'campaignchi'); ?></th>
                        <th><?php esc_html_e('وضعیت', 'campaignchi'); ?></th>
                        <th class="cmc-table__cell--center hide-mobile"><?php esc_html_e('بازدید', 'campaignchi'); ?></th>
                        <th class="cmc-table__cell--center"><?php esc_html_e('سفارش', 'campaignchi'); ?></th>
                        <th class="cmc-table__cell--center hide-mobile"><?php esc_html_e('تعداد فروش', 'campaignchi'); ?></th>
                        <th><?php esc_html_e('فروش', 'campaignchi'); ?></th>
                        <th class="cmc-table__cell--center"><?php esc_html_e('نرخ تبدیل', 'campaignchi'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $campaign): ?>
                        <tr>
                            <td class="cmc-table__cell--bold"><?php echo esc_html($campaign['title']); ?></td>
                            <td>
                                <span class="cmc-badge <?php echo $campaign['type'] === 'flash_sale' ? 'cmc-badge--flash' : 'cmc-badge--primary'; ?>">
                                    <?php echo esc_html($campaign['type_label']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="cmc-badge <?php echo esc_attr($campaign['status_class']); ?>">
                                    <span class="cmc-badge__dot"></span>
                                    <?php echo esc_html($campaign['status_label']); ?>
                                </span>
                            </td>
                            <td class="cmc-table__cell--center cmc-table__cell--muted hide-mobile">
                                <?php echo esc_html(JalaliHelper::toPersianNums((string) $campaign['impressions'])); ?>
                            </td>
                            <td class="cmc-table__cell--center">
                                <span class="cmc-badge cmc-badge--active"><?php echo esc_html(JalaliHelper::toPersianNums((string) $campaign['orders'])); ?></span>
                            </td>
                            <td class="cmc-table__cell--center cmc-table__cell--muted hide-mobile">
                                <?php echo esc_html(JalaliHelper::toPersianNums((string) $campaign['qty'])); ?>
                            </td>
                            <td class="cmc-table__cell--price">
                                <?php echo esc_html($campaign['revenue_full']); ?>
                                <span style="font-size:11px;color:var(--cmc-text-muted)"><?php esc_html_e('تومان', 'campaignchi'); ?></span>
                            </td>
                            <td class="cmc-table__cell--center"><?php echo esc_html($campaign['conversion_label']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string,mixed>> $products
     */
    public function renderProductsFragment(array $products): string
    {
        ob_start();

        $products = array_slice($products, 0, 10);

        if (empty($products)) {
            ?>
            <div class="cmc-empty" style="padding:var(--cmc-space-6) 0">
                <div class="cmc-empty__icon"><i class="ti ti-package-off"></i></div>
                <div class="cmc-empty__title"><?php esc_html_e('محصولی در این بازه فروخته نشده', 'campaignchi'); ?></div>
            </div>
            <?php
            return (string) ob_get_clean();
        }
        ?>
        <div class="cmc-table-wrap">
            <table class="cmc-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('محصول', 'campaignchi'); ?></th>
                        <th><?php esc_html_e('کمپین', 'campaignchi'); ?></th>
                        <th class="cmc-table__cell--center"><?php esc_html_e('تعداد فروش', 'campaignchi'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td class="cmc-table__cell--bold"><?php echo esc_html($product['name']); ?></td>
                            <td class="cmc-table__cell--muted"><?php echo esc_html($product['campaign_title']); ?></td>
                            <td class="cmc-table__cell--center">
                                <span class="cmc-badge cmc-badge--active"><?php echo esc_html(JalaliHelper::toPersianNums((string) $product['qty'])); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------

    private function renderStatCard(string $label, string $value, string $icon, string $color, string $hint): void
    {
        ?>
        <div class="cmc-stat-card">
            <div class="cmc-stat-card__header">
                <span class="cmc-stat-card__label"><?php echo esc_html($label); ?></span>
                <span class="cmc-stat-card__icon cmc-stat-card__icon--<?php echo esc_attr($color); ?>">
                    <i class="ti <?php echo esc_attr($icon); ?>"></i>
                </span>
            </div>
            <div class="cmc-stat-card__value"><?php echo esc_html($value); ?></div>
            <div class="cmc-stat-card__change cmc-stat-card__change--flat">
                <?php echo esc_html($hint); ?>
            </div>
        </div>
        <?php
    }

    /** Skeleton shimmer used while AJAX fragments load. */
    private function renderSkeletonStyles(): void
    {
    ?>
        <style>
            .cmc-skel {
                position: relative;
                overflow: hidden;
                background: var(--cmc-surface-2);
                border-radius: var(--cmc-radius-md);
            }
            .cmc-skel::after {
                content: "";
                position: absolute;
                inset: 0;
                transform: translateX(100%);
                background: linear-gradient(90deg, transparent, rgba(0, 0, 0, 0.04), transparent);
                animation: cmc-skel-shimmer 1.2s infinite;
            }
            @keyframes cmc-skel-shimmer {
                100% { transform: translateX(-100%); }
            }
            .cmc-skel-card { height: 116px; border: 1px solid var(--cmc-border); }
            .cmc-skel-line { height: 14px; margin: 10px var(--cmc-space-5); }
            .cmc-skel-chart { height: 220px; margin-top: var(--cmc-space-4); }
        </style>
<?php
    }

    private function reportService(): ReportService
    {
        return Application::getInstance()->make(ReportService::class);
    }
}