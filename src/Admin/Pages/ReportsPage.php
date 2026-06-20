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
 * A focused, accounting-friendly sales report for campaigns. Every figure
 * shown here is produced by the SAME analytics engine that powers the
 * dashboard (AnalyticsService::getRangeReport → getDailyCampaignData →
 * the campaign-matching logic), only widened from "today" to an arbitrary
 * date range. Nothing here recomputes revenue/orders/views independently.
 *
 * The page is fully server-rendered: changing the range simply reloads
 * the page with `?cmc_page=reports&range=...` (preset) or
 * `&range=custom&from=...&to=...` (custom Jalali range), exactly like the
 * Campaigns list filter bar. JavaScript (reports-page.js) only wires the
 * Jalali date pickers and the "apply custom range" navigation.
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

        $range  = $service->resolveRange($rangeKey, $from, $to);
        $report = $service->getReport($range);

        $baseUrl   = AdminRouter::url('reports');
        $exportUrl = add_query_arg(
            [
                'action' => 'cmc_export_report',
                'nonce'  => wp_create_nonce('cmc_admin'),
                'range'  => $range['key'],
                'from'   => $range['key'] === 'custom' ? $range['start'] : '',
                'to'     => $range['key'] === 'custom' ? $range['end']   : '',
            ],
            admin_url('admin-ajax.php')
        );

        $hasData = $report['summary']['orders'] > 0 || $report['summary']['impressions'] > 0;
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
                        '<strong>' . esc_html($range['label']) . '</strong>'
                    );
                    ?>
                </p>
            </div>
            <a href="<?php echo esc_url($exportUrl); ?>" class="cmc-btn cmc-btn--secondary">
                <i class="ti ti-file-spreadsheet"></i>
                <?php esc_html_e('خروجی اکسل (CSV)', 'campaignchi'); ?>
            </a>
        </div>

        <!-- ---- Range selector ---- -->
        <?php $this->renderRangeBar($service, $range, $baseUrl); ?>

        <!-- ---- KPI cards ---- -->
        <div class="cmc-grid cmc-grid--4 cmc-mb-5">
            <?php
            $summary = $report['summary'];

            $this->renderStatCard(__('فروش کل', 'campaignchi'), $summary['revenue_abbr'], 'ti-cash', 'purple', $summary['revenue_full'] . ' ' . __('تومان', 'campaignchi'));
            $this->renderStatCard(__('تعداد سفارش', 'campaignchi'), JalaliHelper::toPersianNums((string) $summary['orders']), 'ti-shopping-bag', 'orange', __('سفارش شامل محصول کمپین', 'campaignchi'));
            $this->renderStatCard(__('بازدید', 'campaignchi'), $summary['impressions_label'], 'ti-eye', 'blue', __('بازدید محصولات کمپین', 'campaignchi'));
            $this->renderStatCard(__('نرخ تبدیل', 'campaignchi'), $summary['conversion_label'], 'ti-click', 'green', __('سفارش به ازای بازدید', 'campaignchi'));
            ?>
        </div>

        <?php if (!$hasData): ?>
            <div class="cmc-card">
                <div class="cmc-empty" style="padding:var(--cmc-space-10) 0">
                    <div class="cmc-empty__icon"><i class="ti ti-chart-bar-off"></i></div>
                    <div class="cmc-empty__title"><?php esc_html_e('داده‌ای در این بازه ثبت نشده', 'campaignchi'); ?></div>
                    <div class="cmc-empty__desc"><?php esc_html_e('در این بازه‌ی زمانی هیچ فروش یا بازدیدی برای محصولات کمپین ثبت نشده است. بازه‌ی دیگری را انتخاب کنید.', 'campaignchi'); ?></div>
                </div>
            </div>
        <?php else: ?>

            <!-- ---- Daily sales chart ---- -->
            <div class="cmc-card cmc-mb-5">
                <div class="cmc-card__header">
                    <div>
                        <div class="cmc-card__title"><?php esc_html_e('روند فروش روزانه', 'campaignchi'); ?></div>
                        <div class="cmc-card__subtitle"><?php esc_html_e('فروش محصولات کمپین به تفکیک روز (تومان)', 'campaignchi'); ?></div>
                    </div>
                </div>
                <?php $this->renderChart($report['series']); ?>
            </div>

            <!-- ---- Per-campaign breakdown ---- -->
            <div class="cmc-card cmc-card--flush cmc-mb-5">
                <div class="cmc-card__header" style="padding:var(--cmc-space-5) var(--cmc-space-5) 0">
                    <div class="cmc-card__title"><?php esc_html_e('عملکرد کمپین‌ها', 'campaignchi'); ?></div>
                </div>
                <?php $this->renderCampaignsTable($report['campaigns']); ?>
            </div>

            <!-- ---- Top products ---- -->
            <div class="cmc-card cmc-card--flush">
                <div class="cmc-card__header" style="padding:var(--cmc-space-5) var(--cmc-space-5) 0">
                    <div class="cmc-card__title"><?php esc_html_e('پرفروش‌ترین محصولات', 'campaignchi'); ?></div>
                </div>
                <?php $this->renderTopProducts(array_slice($report['top_products'], 0, 10)); ?>
            </div>

        <?php endif; ?>

        <!-- Range base URL for the custom-range navigation in reports-page.js -->
        <input type="hidden" id="cmc-report-base" value="<?php echo esc_attr($baseUrl); ?>">
<?php
    }

    // -------------------------------------------------------
    // RANGE BAR
    // -------------------------------------------------------

    private function renderRangeBar(ReportService $service, array $range, string $baseUrl): void
    {
        $isCustom = $range['key'] === 'custom';
?>
        <div class="cmc-card cmc-mb-5" style="padding:var(--cmc-space-4)">
            <div class="cmc-row cmc-row--sm" style="flex-wrap:wrap;gap:var(--cmc-space-2)">

                <?php foreach ($service->presets() as $key => $label): ?>
                    <?php if ($key === 'custom'): ?>
                        <button type="button"
                            class="cmc-btn cmc-btn--sm <?php echo $isCustom ? 'cmc-btn--primary' : 'cmc-btn--secondary'; ?>"
                            id="cmc-report-custom-toggle">
                            <i class="ti ti-calendar-event"></i>
                            <?php echo esc_html($label); ?>
                        </button>
                    <?php else: ?>
                        <a href="<?php echo esc_url(add_query_arg('range', $key, $baseUrl)); ?>"
                            class="cmc-btn cmc-btn--sm <?php echo $range['key'] === $key ? 'cmc-btn--primary' : 'cmc-btn--secondary'; ?>">
                            <?php echo esc_html($label); ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>

            </div>

            <!-- Custom Jalali range row (revealed by the "بازه دلخواه" button) -->
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

        <script>
            // Pre-fill the custom-range pickers with the active range (edit mode).
            window.CMC_REPORT = {
                isCustom: <?php echo wp_json_encode($isCustom); ?>,
                from: <?php echo wp_json_encode($isCustom ? $range['start'] . 'T00:00' : ''); ?>,
                to: <?php echo wp_json_encode($isCustom ? $range['end'] . 'T00:00' : ''); ?>
            };
        </script>
<?php
    }

    // -------------------------------------------------------
    // CHART (pure-CSS bars, reusing panel.css `.cmc-chart-bars`)
    // -------------------------------------------------------

    /**
     * @param array<int, array{label:string, revenue:float, percent:int, value_label:string}> $series
     */
    private function renderChart(array $series): void
    {
        // Thin, horizontally-scrollable bars so a 30-day range stays readable.
        $minWidth = max(0, count($series) * 34);
?>
        <div style="overflow-x:auto">
            <div class="cmc-chart-bars" style="min-width:<?php echo esc_attr((string) $minWidth); ?>px">
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
    }

    // -------------------------------------------------------
    // CAMPAIGNS TABLE
    // -------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $campaigns
     */
    private function renderCampaignsTable(array $campaigns): void
    {
        if (empty($campaigns)) {
            ?>
            <div class="cmc-empty" style="padding:var(--cmc-space-6) 0">
                <div class="cmc-empty__icon"><i class="ti ti-bolt-off"></i></div>
                <div class="cmc-empty__title"><?php esc_html_e('کمپینی در این بازه فروش نداشته', 'campaignchi'); ?></div>
            </div>
            <?php
            return;
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
    }

    // -------------------------------------------------------
    // TOP PRODUCTS
    // -------------------------------------------------------

    /**
     * @param array<int, array{name:string, qty:int, campaign_title:string}> $products
     */
    private function renderTopProducts(array $products): void
    {
        if (empty($products)) {
            ?>
            <div class="cmc-empty" style="padding:var(--cmc-space-6) 0">
                <div class="cmc-empty__icon"><i class="ti ti-package-off"></i></div>
                <div class="cmc-empty__title"><?php esc_html_e('محصولی در این بازه فروخته نشده', 'campaignchi'); ?></div>
            </div>
            <?php
            return;
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

    private function reportService(): ReportService
    {
        return Application::getInstance()->make(ReportService::class);
    }
}
