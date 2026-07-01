<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JalaliHelper
 *
 * Converts Gregorian dates to Jalali (Persian) dates in pure PHP,
 * with no external extension required.
 *
 * @package Msi\Campaignchi\Helpers
 */
class JalaliHelper
{
    /**
     * Convert a Gregorian date to Jalali.
     *
     * @param int $gy Gregorian year
     * @param int $gm Gregorian month
     * @param int $gd Gregorian day
     * @return array{0: int, 1: int, 2: int} [jalali_year, jalali_month, jalali_day]
     */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $isLeap  = fn($y) => ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;
        $sal_a   = [0, 31, $isLeap($gy) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $_gy     = $gy - 1600;
        $_gm     = $gm - 1;
        $_gd     = $gd - 1;

        $g_day_no = (int)(365 * $_gy + floor(($_gy + 3) / 4) - floor(($_gy + 99) / 100) + floor(($_gy + 399) / 400));
        for ($i = 0; $i < $_gm; $i++) $g_day_no += $sal_a[$i + 1];
        $g_day_no += $_gd;

        $j_day_no = $g_day_no - 79;
        $j_np     = (int)floor($j_day_no / 12053);
        $j_day_no %= 12053;
        $jy        = 979 + 33 * $j_np + 4 * (int)floor($j_day_no / 1461);
        $j_day_no %= 1461;

        if ($j_day_no >= 366) {
            $jy       += (int)floor(($j_day_no - 1) / 365);
            $j_day_no  = ($j_day_no - 1) % 365;
        }

        $j_mi = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        $jm   = 0;
        for ($jm = 0; $jm < 11 && $j_day_no >= $j_mi[$jm]; $jm++) {
            $j_day_no -= $j_mi[$jm];
        }

        return [$jy, $jm + 1, $j_day_no + 1];
    }

    /**
     * Convert a Gregorian datetime string to a Jalali display string.
     * Example: '2025-06-13 10:30:00' -> '۱۴۰۴/۰۳/۲۳'
     * Example with time: '2025-06-13 10:30:00' -> '۱۴۰۴/۰۳/۲۳ ۱۰:۳۰'
     *
     * @param string|null $datetime  Gregorian date string (Y-m-d or Y-m-d H:i:s)
     * @param bool        $withTime  Whether to also show the time
     * @param string      $separator Date separator (default /)
     * @return string Jalali date with Persian digits, or '—' when empty
     */
    public static function toDisplay(?string $datetime, bool $withTime = true, string $separator = '/'): string
    {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }

        // parse date
        $ts = strtotime($datetime);
        if ($ts === false) return '—';

        $gy = (int)date('Y', $ts);
        $gm = (int)date('m', $ts);
        $gd = (int)date('d', $ts);
        $gh = (int)date('H', $ts);
        $gi = (int)date('i', $ts);

        [$jy, $jm, $jd] = self::gregorianToJalali($gy, $gm, $gd);

        $dateStr = self::toPersianNums(
            sprintf('%d%s%02d%s%02d', $jy, $separator, $jm, $separator, $jd)
        );

        if ($withTime) {
            $timeStr = self::toPersianNums(sprintf(' %02d:%02d', $gh, $gi));
            return $dateStr . $timeStr;
        }

        return $dateStr;
    }

    /**
     * Full Jalali date display including weekday name — for page headers/subtitles.
     *
     * Example output: 'دوشنبه، ۲۵ خرداد ۱۴۰۵'
     *
     * If $datetime is omitted, "now" is taken in the site's timezone (via
     * current_time('timestamp'), which is format-compatible with
     * current_time('mysql') — the same convention CampaignResolver uses
     * for its TTL calculations).
     *
     * @param string|null $datetime Gregorian date string (Y-m-d or Y-m-d H:i:s), or null for "now"
     * @return string
     */
    public static function toFullDisplay(?string $datetime = null): string
    {
        $ts = $datetime !== null ? strtotime($datetime) : current_time('timestamp');

        if ($ts === false) {
            return '—';
        }

        $gy = (int) date('Y', $ts);
        $gm = (int) date('m', $ts);
        $gd = (int) date('d', $ts);
        $gw = (int) date('w', $ts); // 0 (Sunday) to 6 (Saturday) — independent of Jalali/Gregorian

        [$jy, $jm, $jd] = self::gregorianToJalali($gy, $gm, $gd);

        return sprintf(
            '%s، %s %s %s',
            self::weekdayName($gw),
            self::toPersianNums((string) $jd),
            self::monthName($jm),
            self::toPersianNums((string) $jy)
        );
    }

    /**
     * Persian weekday name for the output of date('w', $ts).
     * (0 = Sunday ... 6 = Saturday — weekday names are independent of the
     * Jalali/Gregorian calendar, since Jalali also uses the same 7-day cycle.)
     *
     * @param int $gw Output of date('w') — 0 to 6
     * @return string
     */
    public static function weekdayName(int $gw): string
    {
        $names = [
            0 => 'یکشنبه',
            1 => 'دوشنبه',
            2 => 'سه‌شنبه',
            3 => 'چهارشنبه',
            4 => 'پنجشنبه',
            5 => 'جمعه',
            6 => 'شنبه',
        ];

        return $names[$gw] ?? '';
    }

    /**
     * Jalali month name.
     */
    public static function monthName(int $jm): string
    {
        $names = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        return $names[$jm] ?? '';
    }

    /**
     * Convert a Jalali date to Gregorian (inverse of gregorianToJalali).
     * PHP port of the same j2g algorithm used in assets/js/datepicker.js.
     *
     * @return array{0:int, 1:int, 2:int} [gregorian_year, gregorian_month, gregorian_day]
     */
    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy -= 979;
        $jm--;
        $jd--;

        $j_day_no = 365 * $jy + intdiv($jy, 33) * 8 + intdiv($jy % 33 + 3, 4);
        $j_mi     = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        for ($i = 0; $i < $jm; $i++) {
            $j_day_no += $j_mi[$i];
        }
        $j_day_no += $jd;

        $g_day_no = $j_day_no + 79;
        $gy       = 1600 + 400 * intdiv($g_day_no, 146097);
        $g_day_no %= 146097;

        $leap = true;
        if ($g_day_no >= 36525) {
            $g_day_no--;
            $gy       += 100 * intdiv($g_day_no, 36524);
            $g_day_no %= 36524;
            if ($g_day_no >= 365) {
                $g_day_no++;
            } else {
                $leap = false;
            }
        }

        $gy       += 4 * intdiv($g_day_no, 1461);
        $g_day_no %= 1461;
        if ($g_day_no >= 366) {
            $leap = false;
            $g_day_no--;
            $gy       += intdiv($g_day_no, 365);
            $g_day_no %= 365;
        }

        $g_mi = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm   = 0;
        for ($gm = 0; $gm < 12 && $g_day_no >= $g_mi[$gm]; $gm++) {
            $g_day_no -= $g_mi[$gm];
        }

        return [$gy, $gm + 1, $g_day_no + 1];
    }

    /**
     * Number of days in a Jalali month (accounting for leap years in Esfand).
     */
    public static function jDaysInMonth(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }

        // Esfand: 30 days in a leap year, otherwise 29.
        $rem = ((($jy - 474) % 2820 + 474 + 38) * 682) % 2816;

        return $rem < 682 ? 30 : 29;
    }

    /**
     * Convert English digits to Persian digits.
     */
    public static function toPersianNums(string $str): string
    {
        return strtr($str, [
            '0' => '۰',
            '1' => '۱',
            '2' => '۲',
            '3' => '۳',
            '4' => '۴',
            '5' => '۵',
            '6' => '۶',
            '7' => '۷',
            '8' => '۸',
            '9' => '۹',
        ]);
    }
}
