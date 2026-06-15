<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Helpers;

/**
 * JalaliHelper
 *
 * تبدیل تاریخ میلادی به شمسی (جلالی) در PHP
 * بدون نیاز به extension خارجی
 *
 * @package Msi\Campaignchi\Helpers
 */
class JalaliHelper
{
    /**
     * تبدیل تاریخ میلادی به شمسی
     *
     * @param int $gy سال میلادی
     * @param int $gm ماه میلادی
     * @param int $gd روز میلادی
     * @return array{0: int, 1: int, 2: int} [سال_شمسی, ماه_شمسی, روز_شمسی]
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
     * تبدیل datetime string میلادی به نمایش شمسی فارسی
     * مثال: '2025-06-13 10:30:00' → '۱۴۰۴/۰۳/۲۳'
     * مثال با ساعت: '2025-06-13 10:30:00' → '۱۴۰۴/۰۳/۲۳ ۱۰:۳۰'
     *
     * @param string|null $datetime  رشته تاریخ میلادی (Y-m-d یا Y-m-d H:i:s)
     * @param bool        $withTime  آیا ساعت هم نمایش داده شود؟
     * @param string      $separator جداکننده تاریخ (پیشفرض /)
     * @return string تاریخ شمسی با اعداد فارسی یا '—' در صورت خالی بودن
     */
    public static function toDisplay(?string $datetime, bool $withTime = false, string $separator = '/'): string
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
     * نمایش کامل تاریخ شمسی همراه با نام روز هفته — برای هدر/زیرعنوان صفحات.
     *
     * مثال خروجی: 'دوشنبه، ۲۵ خرداد ۱۴۰۵'
     *
     * اگر $datetime داده نشود، «اکنون» بر اساس تایم‌زون سایت در نظر
     * گرفته می‌شود (با current_time('timestamp') که هم‌فرمت با
     * current_time('mysql') است — همان منطقی که در CampaignResolver
     * برای محاسبه‌ی دقیق TTL استفاده شد).
     *
     * @param string|null $datetime رشته تاریخ میلادی (Y-m-d یا Y-m-d H:i:s) یا null برای «اکنون»
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
        $gw = (int) date('w', $ts); // 0 (یکشنبه) تا 6 (شنبه) — مستقل از تقویم شمسی/میلادی

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
     * نام فارسی روز هفته بر اساس خروجی date('w', $ts)
     * (۰ = یکشنبه ... ۶ = شنبه — نام‌های روزهای هفته مستقل از تقویم
     * شمسی/میلادی هستند، چون شمسی هم از همان چرخه‌ی هفت‌روزه استفاده می‌کند)
     *
     * @param int $gw خروجی date('w') — 0 تا 6
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
     * نام ماه شمسی
     */
    public static function monthName(int $jm): string
    {
        $names = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        return $names[$jm] ?? '';
    }

    /**
     * تبدیل اعداد انگلیسی به فارسی
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
