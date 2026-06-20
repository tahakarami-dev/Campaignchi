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
 * Builds the admin "Reports" page entirely from the accurate
 * campaign-sales event log (CampaignSalesRepository) — every figure here
 * reflects ONLY products that were actually sold while genuinely under one
 * of this plugin's campaigns, captured at sale time (no retroactive
 * date-guessing). Impressions for conversion rate still come from the
 * existing per-day stats table (AnalyticsRepository).
 *
 * Responsibilities:
 *   1. Resolve a range selection (7 / 30 / 90 / 365 days, or a custom
 *      Jalali from/to range) into concrete site-local dates.
 *   2. Assemble the full report (summary, chart series, per-campaign
 *      breakdown, top products, and per-order rows for CSV).
 *   3. Flatten the report into an ORDER-CENTRIC CSV for Excel export.
 *
 * @package Msi\Campaignchi\Analytics\Services
 */
class ReportService
{
    /** Hard guard against runaway day-by-day scans. */
    private const MAX_RANGE_DAYS = 366;

    public function __construct(
        private CampaignSalesRepository $sales,
        private AnalyticsRepository $impressions,
        private CampaignRepository $campaigns
    ) {}

    // =========================================================
    // DATE-RANGE RESOLUTION
    // =========================================================

    /**
     * Selectable preset ranges, in display order.
     *
     * @return array<string, string> preset key => Persian label
     */
    public function presets(): array
    {
        return [
            'last7'   => __('۷ روز اخیر', 'campaignchi'),
            'last30'  => __('۳۰ روز اخیر', 'campaignchi'),
            'last90'  => __('۹۰ روز اخیر', 'campaignchi'),
            'last365' => __('یک سال اخیر', 'campaignchi'),
            'custom'  => __('بازه دلخواه', 'campaignchi'),
        ];
    }

    /**
     * Resolve a range selection into concrete, site-local calendar dates,
     * clamped so the end never exceeds "today".
     *
     * @return array{key:string, start:string, end:string, label:string}
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

                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }
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
    // REPORT ASSEMBLY (from the campaign-sales event log)
    // =========================================================

    /**
     * Build the full report for a resolved range.
     *
     * @param array{key:string, start:string, end:string, label:string} $range
     */
    public function getReport(array $range): array
    {
        $start = $range['start'];
        $end   = $range['end'];

        // --- Summary ---
        $summaryRaw  = $this->sales->getSummary($start, $end);
        $impByCamp   = $this->impressions->getImpressionsByCampaign($start, $end);
        $totalImpr   = array_sum($impByCamp);

        $totalRevenue = $summaryRaw['revenue'];
        $totalOrders  = $summaryRaw['orders'];
        $conversion   = $this->conversionRate($totalOrders, $totalImpr);
        $aov          = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0.0;

        // --- Chart series (auto-bucketed by range length) ---
        $series = $this->buildSeries($this->sales->getDailySeries($start, $end), $start, $end);

        // --- Per-campaign breakdown (enriched with model metadata) ---
        $campaigns = $this->buildCampaigns($this->sales->getByCampaign($start, $end), $impByCamp);

        // --- Top products ---
        $topProducts = $this->buildTopProducts($this->sales->getTopProducts($start, $end, 50));

        // --- Order rows for CSV ---
        $orderRows = $this->buildOrderRows($this->sales->getOrderRows($start, $end));

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

    // -------------------------------------------------------
    // SERIES (chart) — bucket by range length so long ranges stay readable
    // -------------------------------------------------------

    /**
     * @param array<string, array{revenue:float, orders:int}> $dailyByDate
     */
    private function buildSeries(array $dailyByDate, string $start, string $end): array
    {
        $dates = $this->datesInRange($start, $end);
        $today = $this->today();
        $count = count($dates);

        // Bucket granularity: daily for short ranges, weekly mid, monthly long.
        $mode = $count <= 31 ? 'day' : ($count <= 120 ? 'week' : 'month');

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
     * Resolve a date to its (bucket key, label) for the chosen granularity.
     *
     * @return array{0:string, 1:string}
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
            // Label = first day of the bucket ("jd ماه").
            return ['w' . $weekIndex, JalaliHelper::toPersianNums((string) $jd) . ' ' . JalaliHelper::monthName($jm)];
        }

        // month
        return ['m' . $jy . '-' . $jm, JalaliHelper::monthName($jm)];
    }

    // -------------------------------------------------------
    // PER-CAMPAIGN
    // -------------------------------------------------------

    /**
     * @param array<int, array{campaign_id:int, revenue:float, orders:int, qty:int}> $rows
     * @param array<int, int> $impByCampaign
     */
    private function buildCampaigns(array $rows, array $impByCampaign): array
    {
        $out = [];

        foreach ($rows as $row) {
            $cid   = $row['campaign_id'];
            $model = $this->campaigns->find($cid);

            $impressions = $impByCampaign[$cid] ?? 0;
            $conversion  = $this->conversionRate($row['orders'], $impressions);

            $out[] = [
                'id'               => $cid,
                'title'            => $model ? $model->title : ('#' . $cid),
                'type'             => $model ? $model->type : 'flash_sale',
                // ⚠️ Bug fix #5: type is always translated via the model
                // (amazing_offer → "پیشنهاد شگفت‌انگیز"); a deleted campaign
                // falls back to a translated map, never the raw machine key.
                'type_label'       => $model ? $model->typeLabel() : $this->fallbackTypeLabel('amazing_offer'),
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

    // -------------------------------------------------------
    // TOP PRODUCTS
    // -------------------------------------------------------

    /**
     * @param array<int, array{product_id:int, qty:int, revenue:float, campaign_id:int}> $rows
     */
    private function buildTopProducts(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $product  = wc_get_product($row['product_id']);
            $campaign = $this->campaigns->find($row['campaign_id']);

            $out[] = [
                'id'             => $row['product_id'],
                'name'           => $product ? $product->get_name() : ('#' . $row['product_id']),
                'qty'            => $row['qty'],
                'revenue'        => $row['revenue'],
                'campaign_title' => $campaign ? $campaign->title : ('#' . $row['campaign_id']),
            ];
        }

        return $out;
    }

    // -------------------------------------------------------
    // ORDER ROWS (for the order-centric CSV)
    // -------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildOrderRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $campaignTitles = $this->idsToCampaignTitles((string) $row['campaign_ids']);
            $productNames   = $this->idsToProductNames((string) $row['product_ids']);

            $out[] = [
                'order_id'       => (int) $row['order_id'],
                'sold_at'        => (string) $row['sold_at'],
                'customer_name'  => (string) $row['customer_name'],
                'customer_email' => (string) $row['customer_email'],
                'order_status'   => $this->orderStatusLabel((string) $row['order_status']),
                'campaigns'      => $campaignTitles,
                'products'       => $productNames,
                'qty'            => (int) $row['qty'],
                'revenue'        => (float) $row['revenue'],
            ];
        }

        return $out;
    }

    // =========================================================
    // CSV EXPORT — ORDER-CENTRIC (bug #3)
    // =========================================================

    /**
     * One section: a flat, order-by-order list. Each row is a single order
     * that contained at least one campaign product, with the customer, the
     * campaign(s) they bought from, the products, quantity and revenue.
     *
     * Numeric columns are plain Latin so Excel parses them as numbers.
     *
     * @param array $report Output of getReport().
     * @param array{label:string} $range
     * @return array<int, array<int, string>>
     */
    public function csvRows(array $report, array $range): array
    {
        $rows = [];

        // ---- Heading ----
        $rows[] = [__('گزارش سفارش‌های کمپین‌چی', 'campaignchi')];
        $rows[] = [__('بازه زمانی:', 'campaignchi'), $range['label']];
        $rows[] = [__('تاریخ تولید گزارش:', 'campaignchi'), JalaliHelper::toFullDisplay()];
        $rows[] = [__('تعداد سفارش:', 'campaignchi'), (string) count($report['order_rows'])];
        $rows[] = [__('مجموع فروش (تومان):', 'campaignchi'), $this->csvInt($report['summary']['revenue'])];
        $rows[] = [];

        // ---- Column headers ----
        $rows[] = [
            __('ردیف', 'campaignchi'),
            __('شماره سفارش', 'campaignchi'),
            __('تاریخ', 'campaignchi'),
            __('نام مشتری', 'campaignchi'),
            __('ایمیل مشتری', 'campaignchi'),
            __('وضعیت سفارش', 'campaignchi'),
            __('کمپین', 'campaignchi'),
            __('محصولات', 'campaignchi'),
            __('تعداد اقلام', 'campaignchi'),
            __('مبلغ کمپینی (تومان)', 'campaignchi'),
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

    /** Download filename for the export. */
    public function exportFilename(array $range): string
    {
        return sprintf('campaignchi-orders-%s_%s.csv', $range['start'], $range['end']);
    }

    // =========================================================
    // INTERNAL HELPERS
    // =========================================================

    private function today(): string
    {
        return (new \DateTime('now', wp_timezone()))->format('Y-m-d');
    }

    private function shiftDays(string $ymd, int $days): string
    {
        $date = new \DateTime($ymd, wp_timezone());
        $date->modify(sprintf('%+d days', $days));

        return $date->format('Y-m-d');
    }

    /**
     * @return string[] All Y-m-d dates in [start, end], clamped to today and
     *                  capped at MAX_RANGE_DAYS.
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

    private function conversionRate(int $orders, int $impressions): float
    {
        return $impressions > 0 ? ($orders / $impressions) * 100 : 0.0;
    }

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
                    /* translators: 1: start date, 2: end date (Jalali). */
                    __('%1$s تا %2$s', 'campaignchi'),
                    $this->jalaliLong($start),
                    $this->jalaliLong($end)
                );
        }
    }

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

    /** Translated label for an order status (Persian). */
    private function orderStatusLabel(string $status): string
    {
        $status = ltrim($status, 'wc-');
        $map    = [
            'processing' => __('در حال پردازش', 'campaignchi'),
            'completed'  => __('تکمیل‌شده', 'campaignchi'),
        ];

        return $map[$status] ?? $status;
    }

    /** Fallback type label when the campaign model is gone — bug #5 guard. */
    private function fallbackTypeLabel(string $type): string
    {
        return match ($type) {
            'flash_sale'    => __('فلش سیل', 'campaignchi'),
            'amazing_offer' => __('پیشنهاد شگفت‌انگیز', 'campaignchi'),
            default         => $type,
        };
    }

    /** Map a comma-separated id list to a "،"-joined list of campaign titles. */
    private function idsToCampaignTitles(string $ids): string
    {
        $titles = [];
        foreach (array_filter(array_map('intval', explode(',', $ids))) as $id) {
            $campaign = $this->campaigns->find($id);
            $titles[] = $campaign ? $campaign->title : ('#' . $id);
        }

        return implode('، ', $titles);
    }

    /** Map a comma-separated id list to a "،"-joined list of product names. */
    private function idsToProductNames(string $ids): string
    {
        $names = [];
        foreach (array_filter(array_map('intval', explode(',', $ids))) as $id) {
            $product = wc_get_product($id);
            $names[] = $product ? $product->get_name() : ('#' . $id);
        }

        return implode('، ', $names);
    }

    private function formatToman(float $value): string
    {
        return JalaliHelper::toPersianNums(number_format($value, 0));
    }

    private function formatPercent(float $value): string
    {
        return JalaliHelper::toPersianNums(number_format($value, 1)) . '٪';
    }

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

    private function csvInt(float $value): string
    {
        return number_format($value, 0, '.', '');
    }
}