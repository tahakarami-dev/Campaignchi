<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Pricing;

use Msi\Campaignchi\Admin\Pages\SettingsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PriceCalculator
 *
 * Pure math — converts a regular price + campaign discount
 * into the final campaign price, honoring the admin-configured
 * discount ceilings (Settings → Campaign engine).
 *
 * @package Msi\Campaignchi\Campaign\Pricing
 */
final class PriceCalculator
{
    /**
     * Apply a campaign discount to a regular price.
     *
     * @param float  $regularPrice  The product's real regular price
     * @param float  $discount      Discount value (percent or fixed amount)
     * @param string $discountType  'percent' | 'fixed'
     * @return float                Final discounted price (never negative)
     */
    public static function apply(float $regularPrice, float $discount, string $discountType): float
    {
        if ($regularPrice <= 0) {
            return $regularPrice;
        }

        // Admin-configured safety ceilings (Settings → Campaign engine).
        [$maxPercent, $maxFixed] = self::discountCeilings();

        if ($discountType === 'percent') {
            // Clamp 0-100, then apply the configured percentage ceiling.
            $discount = min(max($discount, 0), 100);
            $discount = min($discount, $maxPercent);
            $final = $regularPrice * (1 - ($discount / 100));
        } else {
            // Fixed-amount discount: never negative, never above the configured
            // ceiling (0 = unlimited).
            $discount = max($discount, 0);
            if ($maxFixed > 0) {
                $discount = min($discount, $maxFixed);
            }
            $final = $regularPrice - $discount;
        }

        $final = max(0, $final);

        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;

        return round($final, $decimals);
    }

    /**
     * Resolve the admin-configured discount ceilings.
     *
     * @return array{0: float, 1: float} [maxPercent (1-100), maxFixed (0 = unlimited)]
     */
    private static function discountCeilings(): array
    {
        // Fallback ceilings when the settings layer is unavailable: 100% / unlimited.
        if (!class_exists(SettingsPage::class)) {
            return [100.0, 0.0];
        }

        $campaign   = SettingsPage::getCampaign();
        $maxPercent = (float) ($campaign['max_discount_percent'] ?? 100);
        $maxFixed   = (float) ($campaign['max_discount_fixed'] ?? 0);

        // Keep the percentage ceiling within a valid range.
        $maxPercent = min(max($maxPercent, 1.0), 100.0);
        $maxFixed   = max($maxFixed, 0.0);

        return [$maxPercent, $maxFixed];
    }

    /**
     * Compute the effective percent-off for DISPLAY purposes,
     * regardless of how the campaign discount was configured
     * (percent or fixed amount).
     *
     * A fixed-amount discount has a different percentage on every
     * product depending on its regular price — so this must be
     * calculated per-product, at render time.
     *
     * Example: 50,000 fixed discount on a 200,000 product = 25%.
     *
     * @param float $regularPrice
     * @param float $finalPrice
     * @return int Rounded percent (0-100)
     */
    public static function percentOff(float $regularPrice, float $finalPrice): int
    {
        if ($regularPrice <= 0 || $finalPrice >= $regularPrice) {
            return 0;
        }

        $percent = (($regularPrice - $finalPrice) / $regularPrice) * 100;

        return (int) round($percent);
    }
}