<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Services;

use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Helpers\JalaliHelper;

/**
 * Report Service
 *
 * Thin orchestration layer for the admin "Reports" page. It owns NONE of
 * the analytics math: every figure it returns comes straight from
 * AnalyticsService::getRangeReport(), which in turn reuses the exact same
 * campaign-matching / order-scanning engine the dashboard uses
 * (getDailyCampaignData + getCampaignCandidates + findCampaignForProductOnDate).
 *
 * This class only adds three report-specific concerns on top of that
 * single source of truth:
 *   1. Resolving a human-facing date-range selection (presets + a custom
 *      Jalali from/to range) into a concrete Gregorian start/end pair.
 *   2. Enriching each per-campaign row with its current status / discount
 *      label (presentation metadata that isn't part of the analytics math).
 *   3. Flattening the resolved report into CSV rows for the Excel export.
 *
 * @package Msi\Campaignchi\Analytics\Services
 */
class ReportService
{
    /** Maximum span (in days) a single custom range may cover — guards against runaway day-by-day scans. */
    private const MAX_RANGE_DAYS = 366;

    public function __construct(
        private AnalyticsService $analytics,
        private CampaignRepository $campaigns
    ) {}

    // =========================================================
    // DATE-RANGE RESOLUTION
    // =========================================================

    /**
     * The selectable preset ranges, in display order.
     *
     * @return array<string, string> preset key => Persian label
     */
    public function presets(): array
    {
        return [
            'last7'      => __('۷ روز اخیر', 'campaignchi'),
            'last30'     => __('۳۰ روز اخیر', 'campaignchi'),
            'this_month' => __('این ماه', 'campaignchi'),
            'last_month' => __('ماه قبل', 'campaignchi'),
            'custom'     => __('بازه دلخواه', 'campaignchi'),
        ];
    }

    /**
     * Resolve a range selection into concrete, site-local calendar dates.
     *
     * All boundaries are produced in the SITE timezone (wp_timezone()) and
     * clamped so the end never exceeds "today" — exactly matching the date
     * semantics AnalyticsService already uses for its daily ranges.
     *
     * @param string|null $key  One of presets() keys; defaults to 'last7'.
     * @param string|null $from Custom-range start (ISO or Y-m-d), only used when $key === 'custom'.
     * @param string|null $to   Custom-range end   (ISO or Y-m-d), only used when $key === 'custom'.
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

            case 'this_month':
                [$start, $end] = $this->jalaliThisMonth($today);
                break;

            case 'last_month':
                [$start, $end] = $this->jalaliLastMonth($today);
                break;

            case 'custom':
                $start = $from ? substr($from, 0, 10) : $this->shiftDays($today, -6);
                $end   = $to   ? substr($to,   0, 10) : $today;

                // Defensive normalization: swap if reversed, clamp to today.
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
    // REPORT DATA
    // =========================================================

    /**
     * Build the full report for a resolved range, enriching each campaign
     * row with presentation-only metadata (status / discount labels).
     *
     * @param array{start:string, end:string} $range
     * @return array Same shape as AnalyticsService::getRangeReport(), with
     *               extra status/discount fields per campaign.
     */
    public function getReport(array $range): array
    {
        $report = $this->analytics->getRangeReport($range['start'], $range['end']);

        foreach ($report['campaigns'] as &$campaign) {
            $model = $this->campaigns->find((int) $campaign['id']);

            $campaign['status_label']   = $model ? $model->statusLabel()      : '—';
            $campaign['status_class']   = $model ? $model->statusBadgeClass() : 'cmc-badge--draft';
            $campaign['discount_label'] = $model ? $model->discountLabel()    : '—';
            $campaign['type_label']     = $model ? $model->typeLabel()        : $campaign['type'];
        }
        unset($campaign);

        return $report;
    }

    // =========================================================
    // CSV EXPORT
    // =========================================================

    /**
     * Flatten a resolved report into CSV rows (sections separated by blank
     * rows). Numeric columns are emitted as plain Latin integers so Excel
     * treats them as real numbers (and SUM/AVERAGE formulas work); only
     * labels and dates are Persian text.
     *
     * @param array $report Output of getReport().
     * @param array{label:string} $range
     * @return array<int, array<int, string>>
     */
    public function csvRows(array $report, array $range): array
    {
        $rows = [];

        // ---- Document heading ----
        $rows[] = [__('گزارش فروش کمپین‌ها — کمپین‌چی', 'campaignchi')];
        $rows[] = [__('بازه زمانی:', 'campaignchi'), $range['label']];
        $rows[] = [__('تاریخ تولید گزارش:', 'campaignchi'), JalaliHelper::toFullDisplay()];
        $rows[] = [];

        // ---- Summary ----
        $summary = $report['summary'];
        $rows[]  = [__('خلاصه', 'campaignchi')];
        $rows[]  = [__('فروش کل (تومان)', 'campaignchi'),        $this->csvInt($summary['revenue'])];
        $rows[]  = [__('تعداد سفارش', 'campaignchi'),            (string) $summary['orders']];
        $rows[]  = [__('بازدید محصولات کمپین', 'campaignchi'),   (string) $summary['impressions']];
        $rows[]  = [__('نرخ تبدیل (٪)', 'campaignchi'),          $this->csvFloat($summary['conversion'])];
        $rows[]  = [__('میانگین ارزش سفارش (تومان)', 'campaignchi'), $this->csvInt($summary['aov'])];
        $rows[]  = [];

        // ---- Per-campaign breakdown ----
        $rows[] = [__('تفکیک کمپین‌ها', 'campaignchi')];
        $rows[] = [
            __('ردیف', 'campaignchi'),
            __('نام کمپین', 'campaignchi'),
            __('نوع', 'campaignchi'),
            __('وضعیت', 'campaignchi'),
            __('تخفیف', 'campaignchi'),
            __('بازدید', 'campaignchi'),
            __('سفارش', 'campaignchi'),
            __('تعداد فروش', 'campaignchi'),
            __('فروش (تومان)', 'campaignchi'),
            __('نرخ تبدیل (٪)', 'campaignchi'),
        ];

        $i = 1;
        foreach ($report['campaigns'] as $campaign) {
            $rows[] = [
                (string) $i++,
                $campaign['title'],
                $campaign['type_label'],
                $campaign['status_label'],
                $campaign['discount_label'],
                (string) $campaign['impressions'],
                (string) $campaign['orders'],
                (string) $campaign['qty'],
                $this->csvInt($campaign['revenue']),
                $this->csvFloat($campaign['conversion']),
            ];
        }
        $rows[] = [];

        // ---- Daily sales ----
        $rows[] = [__('فروش روزانه', 'campaignchi')];
        $rows[] = [__('تاریخ', 'campaignchi'), __('فروش (تومان)', 'campaignchi'), __('سفارش', 'campaignchi')];
        foreach ($report['series'] as $day) {
            $rows[] = [
                JalaliHelper::toDisplay($day['date']),
                $this->csvInt($day['revenue']),
                (string) $day['orders'],
            ];
        }
        $rows[] = [];

        // ---- Top products ----
        $rows[] = [__('پرفروش‌ترین محصولات', 'campaignchi')];
        $rows[] = [
            __('ردیف', 'campaignchi'),
            __('محصول', 'campaignchi'),
            __('کمپین', 'campaignchi'),
            __('تعداد فروش', 'campaignchi'),
        ];
        $i = 1;
        foreach ($report['top_products'] as $product) {
            $rows[] = [
                (string) $i++,
                $product['name'],
                $product['campaign_title'],
                (string) $product['qty'],
            ];
        }

        return $rows;
    }

    /**
     * Build the download filename for the export, e.g.
     * "campaignchi-report-2025-06-19.csv".
     */
    public function exportFilename(array $range): string
    {
        return sprintf('campaignchi-report-%s_%s.csv', $range['start'], $range['end']);
    }

    // =========================================================
    // INTERNAL HELPERS
    // =========================================================

    /** Today's date (Y-m-d) in the site timezone. */
    private function today(): string
    {
        return (new \DateTime('now', wp_timezone()))->format('Y-m-d');
    }

    /** Shift a Y-m-d date by N days (negative = backwards), site-local. */
    private function shiftDays(string $ymd, int $days): string
    {
        $date = new \DateTime($ymd, wp_timezone());
        $date->modify(sprintf('%+d days', $days));

        return $date->format('Y-m-d');
    }

    /**
     * Start/end Gregorian dates of the CURRENT Jalali month (1st → today).
     *
     * @return array{0:string, 1:string} [start Y-m-d, end Y-m-d]
     */
    private function jalaliThisMonth(string $today): array
    {
        $ts = strtotime($today);
        [$jy, $jm] = JalaliHelper::gregorianToJalali((int) date('Y', $ts), (int) date('m', $ts), (int) date('d', $ts));

        [$gy, $gm, $gd] = JalaliHelper::jalaliToGregorian($jy, $jm, 1);
        $start = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);

        return [$start, $today];
    }

    /**
     * Start/end Gregorian dates of the PREVIOUS full Jalali month.
     *
     * @return array{0:string, 1:string} [start Y-m-d, end Y-m-d]
     */
    private function jalaliLastMonth(string $today): array
    {
        $ts = strtotime($today);
        [$jy, $jm] = JalaliHelper::gregorianToJalali((int) date('Y', $ts), (int) date('m', $ts), (int) date('d', $ts));

        $pjy = $jy;
        $pjm = $jm - 1;
        if ($pjm < 1) {
            $pjm = 12;
            $pjy--;
        }

        [$sy, $sm, $sd] = JalaliHelper::jalaliToGregorian($pjy, $pjm, 1);
        $lastDay        = JalaliHelper::jDaysInMonth($pjy, $pjm);
        [$ey, $em, $ed] = JalaliHelper::jalaliToGregorian($pjy, $pjm, $lastDay);

        return [
            sprintf('%04d-%02d-%02d', $sy, $sm, $sd),
            sprintf('%04d-%02d-%02d', $ey, $em, $ed),
        ];
    }

    /** Human-readable Persian label for a resolved range. */
    private function rangeLabel(string $key, string $start, string $end): string
    {
        switch ($key) {
            case 'last7':
                return __('۷ روز اخیر', 'campaignchi');

            case 'last30':
                return __('۳۰ روز اخیر', 'campaignchi');

            case 'this_month':
            case 'last_month':
                $ts = strtotime($start);
                [$jy, $jm] = JalaliHelper::gregorianToJalali((int) date('Y', $ts), (int) date('m', $ts), (int) date('d', $ts));
                return JalaliHelper::monthName($jm) . ' ' . JalaliHelper::toPersianNums((string) $jy);

            default: // custom
                return sprintf(
                    /* translators: 1: start date, 2: end date (both Jalali). */
                    __('%1$s تا %2$s', 'campaignchi'),
                    $this->jalaliLong($start),
                    $this->jalaliLong($end)
                );
        }
    }

    /** Format a Y-m-d as a long Jalali date, e.g. "۲۳ خرداد ۱۴۰۴". */
    private function jalaliLong(string $ymd): string
    {
        $ts = strtotime($ymd);
        [$jy, $jm, $jd] = JalaliHelper::gregorianToJalali((int) date('Y', $ts), (int) date('m', $ts), (int) date('d', $ts));

        return JalaliHelper::toPersianNums((string) $jd)
            . ' ' . JalaliHelper::monthName($jm)
            . ' ' . JalaliHelper::toPersianNums((string) $jy);
    }

    /** Plain integer string for CSV numeric cells (no thousands separator → Excel parses it as a number). */
    private function csvInt(float $value): string
    {
        return number_format($value, 0, '.', '');
    }

    /** One-decimal float string for CSV numeric cells. */
    private function csvFloat(float $value): string
    {
        return number_format($value, 1, '.', '');
    }
}
