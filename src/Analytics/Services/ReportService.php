<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Services;

use Msi\Campaignchi\Analytics\Repositories\AnalyticsRepository;
use Msi\Campaignchi\Analytics\Repositories\CampaignSalesRepository;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Helpers\JalaliHelper;

/**
 * Report Service
 *
 * Builds the admin "Reports" page entirely from the accurate campaign-sales
 * event log (CampaignSalesRepository).
 *
 * DATA ACCURACY GUARANTEE:
 *   Every figure here reflects ONLY products that were provably sold while
 *   under a campaign — captured at sale time by CampaignSalesRecorder.
 *   There is no retroactive guessing, no WooCommerce order re-scanning, and
 *   no risk of counting regular (non-campaign) items.
 *
 * WHAT IS COUNTED:
 *   - revenue: SUM of campaign-attributed line item totals (post-discount, excl. tax)
 *   - orders:  COUNT(DISTINCT order_id) — orders with ≥1 campaign item
 *   - qty:     SUM of campaign item quantities only
 *   Note: an order that mixes campaign + regular items is counted ONCE in
 *   `orders`, but only its campaign items contribute to `revenue` and `qty`.
 *
 * IMPRESSIONS:
 *   Impressions (page views of campaign pages) still come from the separate
 *   wp_cmc_campaign_stats table via AnalyticsRepository. They are used only
 *   for conversion rate calculation.
 *
 * RESPONSIBILITIES:
 *   1. Resolve a range selection (preset or custom Jalali) into Y-m-d dates.
 *   2. Assemble the full report: summary KPIs, chart series, per-campaign
 *      breakdown, top products, and per-order rows for CSV export.
 *   3. Flatten the report into an order-centric CSV for Excel download.
 *
 * @package Msi\Campaignchi\Analytics\Services
 */
class ReportService
{
    /**
     * Hard cap on the number of days a single report can span.
     * Prevents runaway loops / memory issues for absurdly large custom ranges.
     */
    private const MAX_RANGE_DAYS = 366;

    public function __construct(
        private CampaignSalesRepository $sales,
        private AnalyticsRepository     $impressions,
        private CampaignRepository      $campaigns
    ) {}

    // =========================================================
    // DATE-RANGE RESOLUTION
    // =========================================================

    /**
     * Selectable preset ranges in display order.
     *
     * @return array<string, string> preset key => Persian label
     */
    public function presets(): array
    {
        return [
            'last7'   => __('۷ روز اخیر',   'campaignchi'),
            'last30'  => __('۳۰ روز اخیر',  'campaignchi'),
            'last90'  => __('۹۰ روز اخیر',  'campaignchi'),
            'last365' => __('یک سال اخیر',   'campaignchi'),
            'custom'  => __('بازه دلخواه',   'campaignchi'),
        ];
    }

    /**
     * Resolve a range selection into concrete site-local calendar dates,
     * clamped so the end never exceeds today.
     *
     * @param string|null $key  Preset key ('last7', 'last30', 'last90', 'last365', 'custom')
     * @param string|null $from Custom range start (Y-m-d, used only when $key = 'custom')
     * @param string|null $to   Custom range end   (Y-m-d, used only when $key = 'custom')
     * @return array{key: string, start: string, end: string, label: string}
     */
    public function resolveRange(?string $key, ?string $from = null, ?string $to = null): array
    {
        $today = $this->today();
        $key   = $key ?: 'last7';

        switch ($key) {
            case 'last30':
                $start = $this->shiftDays($today, -29);
                $end   = $today;
                break;

            case 'last90':
                $start = $this->shiftDays($today, -89);
                $end   = $today;
                break;

            case 'last365':
                $start = $this->shiftDays($today, -364);
                $end   = $today;
                break;

            case 'custom':
                $start = $from ? substr($from, 0, 10) : $this->shiftDays($today, -6);
                $end   = $to   ? substr($to,   0, 10) : $today;

                // Ensure start ≤ end (swap if user entered them backwards).
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }

                // Never show future data.
                if ($end > $today) {
                    $end = $today;
                }
                break;

            case 'last7':
            default:
                $key   = 'last7';
                $start = $this->shiftDays($today, -6);
                $end   = $today;
                break;
        }

        return [
            'key'   => $key,
            'start' => $start,
            'end'   => $end,
            'label' => $this->rangeLabel($key, $start, $end),
        ];
    }

    // =========================================================
    // REPORT ASSEMBLY
    // =========================================================

    /**
     * Build the full report for a resolved date range.
     *
     * All numbers come exclusively from the campaign-sales event log table.
     * No WooCommerce order re-scanning happens here.
     *
     * @param array{key: string, start: string, end: string, label: string} $range
     * @return array{
     *   summary:      array<string, mixed>,
     *   series:       array<int, array<string, mixed>>,
     *   campaigns:    array<int, array<string, mixed>>,
     *   top_products: array<int, array<string, mixed>>,
     *   order_rows:   array<int, array<string, mixed>>
     * }
     */
    public function getReport(array $range): array
    {
        $start = $range['start'];
        $end   = $range['end'];

        // --- Summary KPIs (from event log) ---
        $summaryRaw = $this->sales->getSummary($start, $end);

        // --- Impressions per campaign (from stats table) ---
        $impByCampaign = $this->impressions->getImpressionsByCampaign($start, $end);
        $totalImpr     = array_sum($impByCampaign);

        $totalRevenue = $summaryRaw['revenue'];
        $totalOrders  = $summaryRaw['orders'];
        $conversion   = $this->conversionRate($totalOrders, $totalImpr);
        $aov          = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0.0;

        // --- Chart series (auto-bucketed by range length) ---
        $series = $this->buildSeries(
            $this->sales->getDailySeries($start, $end),
            $start,
            $end
        );

        // --- Per-campaign breakdown (enriched with Campaign model metadata) ---
        $campaigns = $this->buildCampaignRows(
            $this->sales->getByCampaign($start, $end),
            $impByCampaign
        );

        // --- Top products by quantity ---
        $topProducts = $this->buildTopProductRows(
            $this->sales->getTopProducts($start, $end, 50)
        );

        // --- Per-order rows for CSV export ---
        $orderRows = $this->buildOrderRows(
            $this->sales->getOrderRows($start, $end)
        );

        return [
            'summary' => [
                'revenue'           => $totalRevenue,
                'revenue_abbr'      => $this->abbreviateNumber($totalRevenue),
                'revenue_full'      => $this->formatToman($totalRevenue),
                'orders'            => $totalOrders,
                'qty'               => $summaryRaw['qty'],
                'impressions'       => $totalImpr,
                'impressions_label' => $this->abbreviateNumber((float) $totalImpr),
                'conversion'        => $conversion,
                'conversion_label'  => $this->formatPercent($conversion),
                'aov'               => $aov,
                'aov_label'         => $this->formatToman($aov),
            ],
            'series'       => $series,
            'campaigns'    => $campaigns,
            'top_products' => $topProducts,
            'order_rows'   => $orderRows,
        ];
    }

    // =========================================================
    // CHART SERIES BUILDER
    // =========================================================

    /**
     * Build chart-ready series from raw daily data, automatically choosing
     * granularity based on the range length:
     *   ≤31 days  → daily buckets
     *   32–120    → weekly buckets (7-day groups)
     *   >120      → monthly buckets
     *
     * Gap days (no campaign sales) are filled with zero values so the chart
     * never has holes in the x-axis.
     *
     * @param array<string, array{revenue: float, orders: int}> $dailyByDate
     *   Keyed by Y-m-d; only dates WITH sales are present (gaps are absent).
     * @param string $start Y-m-d range start
     * @param string $end   Y-m-d range end
     * @return array<int, array{
     *   label: string, revenue: float, orders: int,
     *   is_today: bool, percent: int, value_label: string
     * }>
     */
    private function buildSeries(array $dailyByDate, string $start, string $end): array
    {
        $dates = $this->datesInRange($start, $end);
        $today = $this->today();
        $count = count($dates);

        $mode  = $count <= 31 ? 'day' : ($count <= 120 ? 'week' : 'month');
        $buckets = [];
        $index   = 0;

        foreach ($dates as $date) {
            $daily = $dailyByDate[$date] ?? ['revenue' => 0.0, 'orders' => 0];
            [$key, $label] = $this->bucketFor($date, $mode, $index);

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'label'    => $label,
                    'revenue'  => 0.0,
                    'orders'   => 0,
                    'is_today' => false,
                ];
            }

            $buckets[$key]['revenue'] += $daily['revenue'];
            $buckets[$key]['orders']  += $daily['orders'];

            if ($date === $today) {
                $buckets[$key]['is_today'] = true;
            }

            $index++;
        }

        // Normalize percentages relative to the tallest bar.
        $maxRevenue = max(array_column($buckets, 'revenue') ?: [0]);
        $maxRevenue = $maxRevenue > 0 ? $maxRevenue : 1;

        $series = [];
        foreach ($buckets as $bucket) {
            $series[] = [
                'label'       => $bucket['label'],
                'revenue'     => $bucket['revenue'],
                'orders'      => $bucket['orders'],
                'is_today'    => $bucket['is_today'],
                'percent'     => (int) round(($bucket['revenue'] / $maxRevenue) * 100),
                'value_label' => $this->abbreviateNumber($bucket['revenue']),
            ];
        }

        return $series;
    }

    /**
     * Resolve a date to its (bucket key, Persian label) for the chosen granularity.
     *
     * Daily   → label = Jalali day number (e.g. "۱۵")
     * Weekly  → label = first day of the 7-day bucket ("۱۵ خرداد")
     * Monthly → label = Jalali month name ("خرداد")
     *
     * @return array{0: string, 1: string}  [bucket_key, display_label]
     */
    private function bucketFor(string $date, string $mode, int $index): array
    {
        $ts = strtotime($date);
        [$jy, $jm, $jd] = JalaliHelper::gregorianToJalali(
            (int) date('Y', $ts),
            (int) date('m', $ts),
            (int) date('d', $ts)
        );

        if ($mode === 'day') {
            return [$date, JalaliHelper::toPersianNums((string) $jd)];
        }

        if ($mode === 'week') {
            $weekIndex = intdiv($index, 7);
            $label     = JalaliHelper::toPersianNums((string) $jd) . ' ' . JalaliHelper::monthName($jm);
            return ['w' . $weekIndex, $label];
        }

        // Monthly bucket.
        return ['m' . $jy . '-' . $jm, JalaliHelper::monthName($jm)];
    }

    // =========================================================
    // PER-CAMPAIGN BREAKDOWN BUILDER
    // =========================================================

    /**
     * Enrich raw campaign sales rows with Campaign model metadata
     * (title, type, status, discount label) and computed conversion rate.
     *
     * If a campaign has been deleted, fall back to safe defaults rather than
     * crashing — deleted campaigns still have historical sales data.
     *
     * @param array<int, array{campaign_id: int, revenue: float, orders: int, qty: int}> $rows
     * @param array<int, int> $impByCampaign  campaign_id => impression count
     * @return array<int, array<string, mixed>>
     */
    private function buildCampaignRows(array $rows, array $impByCampaign): array
    {
        $out = [];

        foreach ($rows as $row) {
            $cid    = $row['campaign_id'];
            $model  = $this->campaigns->find($cid);

            $impressions = $impByCampaign[$cid] ?? 0;
            $conversion  = $this->conversionRate($row['orders'], $impressions);

            $out[] = [
                'id'               => $cid,
                'title'            => $model ? $model->title          : ('#' . $cid),
                'type'             => $model ? $model->type           : 'unknown',
                // Human-readable type label; falls back gracefully for deleted campaigns.
                'type_label'       => $model
                    ? $model->typeLabel()
                    : $this->fallbackTypeLabel($row['type'] ?? 'amazing_offer'),
                'status_label'     => $model ? $model->statusLabel()      : '—',
                'status_class'     => $model ? $model->statusBadgeClass() : 'cmc-badge--draft',
                'discount_label'   => $model ? $model->discountLabel()    : '—',
                'revenue'          => $row['revenue'],
                'revenue_full'     => $this->formatToman($row['revenue']),
                'orders'           => $row['orders'],
                'qty'              => $row['qty'],
                'impressions'      => $impressions,
                'conversion'       => $conversion,
                'conversion_label' => $this->formatPercent($conversion),
            ];
        }

        return $out;
    }

    // =========================================================
    // TOP PRODUCTS BUILDER
    // =========================================================

    /**
     * Enrich raw top-product rows with WooCommerce product names and
     * the associated campaign title.
     *
     * @param array<int, array{product_id: int, qty: int, revenue: float, campaign_id: int}> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildTopProductRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $product  = wc_get_product($row['product_id']);
            $campaign = $this->campaigns->find($row['campaign_id']);

            $out[] = [
                'id'             => $row['product_id'],
                'name'           => $product  ? $product->get_name()  : ('#' . $row['product_id']),
                'qty'            => $row['qty'],
                'revenue'        => $row['revenue'],
                'campaign_title' => $campaign ? $campaign->title      : ('#' . $row['campaign_id']),
            ];
        }

        return $out;
    }

    // =========================================================
    // ORDER ROWS BUILDER (for CSV export)
    // =========================================================

    /**
     * Hydrate raw order rows with human-readable campaign titles, product
     * names, and formatted status labels.
     *
     * Note: revenue here is the SUM of campaign-attributed item totals for
     * that order — NOT the WooCommerce order total. An order containing both
     * campaign and non-campaign products will show only the campaign portion.
     *
     * @param array<int, array<string, mixed>> $rows Raw rows from getOrderRows()
     * @return array<int, array<string, mixed>>
     */
    private function buildOrderRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'order_id'       => (int)    $row['order_id'],
                'sold_at'        => (string) $row['sold_at'],
                'customer_name'  => (string) $row['customer_name'],
                'customer_email' => (string) $row['customer_email'],
                'order_status'   => $this->orderStatusLabel((string) $row['order_status']),
                'campaigns'      => $this->idsToCampaignTitles((string) $row['campaign_ids']),
                'products'       => $this->idsToProductNames((string) $row['product_ids']),
                'qty'            => (int)    $row['qty'],
                'revenue'        => (float)  $row['revenue'],
            ];
        }

        return $out;
    }

    // =========================================================
    // CSV EXPORT
    // =========================================================

    /**
     * Build the complete CSV row array for an order-centric export.
     *
     * The exported file is order-centric: one row per order, showing
     * which campaigns were involved, which products, and the campaign-
     * attributed revenue (not the full order total).
     *
     * Numeric columns are plain Latin digits so Excel auto-detects them
     * as numbers (no locale formatting).
     *
     * @param array  $report Full report output from getReport()
     * @param array{label: string} $range Resolved range (for headers)
     * @return array<int, array<int, string>>
     */
    public function csvRows(array $report, array $range): array
    {
        $rows = [];

        // File metadata header block.
        $rows[] = [__('گزارش سفارش‌های کمپین‌چی', 'campaignchi')];
        $rows[] = [__('بازه زمانی:', 'campaignchi'), $range['label']];
        $rows[] = [__('تاریخ تولید گزارش:', 'campaignchi'), JalaliHelper::toFullDisplay()];
        $rows[] = [__('تعداد سفارش:', 'campaignchi'), (string) count($report['order_rows'])];
        $rows[] = [__('مجموع فروش کمپینی (تومان):', 'campaignchi'), $this->csvInt($report['summary']['revenue'])];
        $rows[] = [];

        // Column header row.
        $rows[] = [
            __('ردیف',                   'campaignchi'),
            __('شماره سفارش',            'campaignchi'),
            __('تاریخ',                  'campaignchi'),
            __('نام مشتری',              'campaignchi'),
            __('ایمیل مشتری',            'campaignchi'),
            __('وضعیت سفارش',            'campaignchi'),
            __('کمپین',                  'campaignchi'),
            __('محصولات کمپینی',         'campaignchi'),
            __('تعداد اقلام کمپینی',     'campaignchi'),
            __('مبلغ کمپینی (تومان)',    'campaignchi'),
        ];

        $i = 1;
        foreach ($report['order_rows'] as $order) {
            $rows[] = [
                (string) $i++,
                (string) $order['order_id'],
                JalaliHelper::toDisplay($order['sold_at'], true),
                $order['customer_name'] !== '' ? $order['customer_name'] : __('مهمان', 'campaignchi'),
                $order['customer_email'],
                $order['order_status'],
                $order['campaigns'],
                $order['products'],
                (string) $order['qty'],
                $this->csvInt($order['revenue']),
            ];
        }

        return $rows;
    }

    /**
     * Generate the download filename for the CSV export.
     *
     * @param array{start: string, end: string} $range
     */
    public function exportFilename(array $range): string
    {
        return sprintf('campaignchi-orders-%s_%s.csv', $range['start'], $range['end']);
    }

    // =========================================================
    // DATE HELPERS
    // =========================================================

    /**
     * Today's date in the WP site timezone (Y-m-d).
     */
    private function today(): string
    {
        return (new \DateTime('now', wp_timezone()))->format('Y-m-d');
    }

    /**
     * Shift a Y-m-d date string by $days (negative = past, positive = future).
     *
     * @param string $ymd  Base date
     * @param int    $days Number of days to shift (may be negative)
     * @return string Y-m-d
     */
    private function shiftDays(string $ymd, int $days): string
    {
        $date = new \DateTime($ymd, wp_timezone());
        $date->modify(sprintf('%+d days', $days));

        return $date->format('Y-m-d');
    }

    /**
     * Return every Y-m-d date in [start, end], clamped to today and
     * capped at MAX_RANGE_DAYS.
     *
     * @return string[]
     */
    private function datesInRange(string $start, string $end): array
    {
        $today = $this->today();
        if ($end > $today) {
            $end = $today;
        }
        if ($start > $end) {
            $start = $end;
        }

        $dates  = [];
        $cursor = new \DateTime($start, wp_timezone());
        $endDt  = new \DateTime($end, wp_timezone());
        $guard  = 0;

        while ($cursor <= $endDt && $guard < self::MAX_RANGE_DAYS) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
            $guard++;
        }

        return $dates;
    }

    // =========================================================
    // FORMATTING HELPERS
    // =========================================================

    /**
     * Compute conversion rate as a percentage.
     * Returns 0 if impressions is zero (avoid division by zero).
     */
    private function conversionRate(int $orders, int $impressions): float
    {
        return $impressions > 0 ? ($orders / $impressions) * 100 : 0.0;
    }

    /** Build a human-readable label for a range (used in UI headers and CSV). */
    private function rangeLabel(string $key, string $start, string $end): string
    {
        switch ($key) {
            case 'last7':
                return __('۷ روز اخیر', 'campaignchi');
            case 'last30':
                return __('۳۰ روز اخیر', 'campaignchi');
            case 'last90':
                return __('۹۰ روز اخیر', 'campaignchi');
            case 'last365':
                return __('یک سال اخیر', 'campaignchi');
            default: // custom
                return sprintf(
                    /* translators: 1: start date, 2: end date (both Jalali). */
                    __('%1$s تا %2$s', 'campaignchi'),
                    $this->jalaliLong($start),
                    $this->jalaliLong($end)
                );
        }
    }

    /**
     * Format a Y-m-d Gregorian date as a long Jalali string (e.g. "۱۵ خرداد ۱۴۰۳").
     */
    private function jalaliLong(string $ymd): string
    {
        $ts = strtotime($ymd);
        [$jy, $jm, $jd] = JalaliHelper::gregorianToJalali(
            (int) date('Y', $ts),
            (int) date('m', $ts),
            (int) date('d', $ts)
        );

        return JalaliHelper::toPersianNums((string) $jd)
            . ' ' . JalaliHelper::monthName($jm)
            . ' ' . JalaliHelper::toPersianNums((string) $jy);
    }

    /**
     * Translate a WooCommerce order status slug to a Persian label.
     * Strips the "wc-" prefix if present.
     */
    private function orderStatusLabel(string $status): string
    {
        $status = ltrim($status, 'wc-');

        $map = [
            'processing' => __('در حال پردازش', 'campaignchi'),
            'completed'  => __('تکمیل‌شده',     'campaignchi'),
        ];

        return $map[$status] ?? $status;
    }

    /**
     * Fallback type label for deleted campaigns where the Campaign model is gone.
     * Ensures the reports table always shows a human-readable type, not a raw key.
     */
    private function fallbackTypeLabel(string $type): string
    {
        return match ($type) {
            'flash_sale'    => __('فلش سیل',             'campaignchi'),
            'amazing_offer' => __('پیشنهاد شگفت‌انگیز', 'campaignchi'),
            default         => $type,
        };
    }

    /**
     * Map a comma-separated campaign ID list to a "،"-joined string of titles.
     * Used in order rows for the CSV export.
     */
    private function idsToCampaignTitles(string $ids): string
    {
        $titles = [];

        foreach (array_filter(array_map('intval', explode(',', $ids))) as $id) {
            $campaign = $this->campaigns->find($id);
            $titles[] = $campaign ? $campaign->title : ('#' . $id);
        }

        return implode('، ', $titles);
    }

    /**
     * Map a comma-separated product ID list to a "،"-joined string of product names.
     * Used in order rows for the CSV export.
     */
    private function idsToProductNames(string $ids): string
    {
        $names = [];

        foreach (array_filter(array_map('intval', explode(',', $ids))) as $id) {
            $product = wc_get_product($id);
            $names[] = $product ? $product->get_name() : ('#' . $id);
        }

        return implode('، ', $names);
    }

    /**
     * Format a number as Persian Toman with thousands separator.
     * e.g. 1500000 → "۱٬۵۰۰٬۰۰۰"
     */
    private function formatToman(float $value): string
    {
        return JalaliHelper::toPersianNums(number_format($value, 0));
    }

    /**
     * Format a percentage with one decimal place and Persian digits.
     * e.g. 3.7 → "۳٫۷٪"
     */
    private function formatPercent(float $value): string
    {
        return JalaliHelper::toPersianNums(number_format($value, 1)) . '٪';
    }

    /**
     * Abbreviate a large number with K/M suffix for compact display.
     * e.g. 1500000 → "۱٫۵M", 25000 → "۲۵K"
     */
    private function abbreviateNumber(float $value): string
    {
        if ($value >= 1_000_000) {
            $formatted = rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.');
            return JalaliHelper::toPersianNums($formatted) . 'M';
        }

        if ($value >= 1_000) {
            $formatted = rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.');
            return JalaliHelper::toPersianNums($formatted) . 'K';
        }

        return JalaliHelper::toPersianNums(number_format($value, 0));
    }

    /**
     * Format a float as a plain integer string for CSV columns.
     * Uses Latin digits so Excel parses them as numbers (no locale separator).
     */
    private function csvInt(float $value): string
    {
        return number_format($value, 0, '.', '');
    }
}