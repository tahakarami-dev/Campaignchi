<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Pricing;

/**
 * PriceCalculator
 *
 * Pure math — converts a regular price + campaign discount
 * into the final campaign price.
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

        if ($discountType === 'percent') {
            $discount = min(max($discount, 0), 100); // clamp 0-100
            $final = $regularPrice * (1 - ($discount / 100));
        } else {
            // fixed amount discount
            $final = $regularPrice - max($discount, 0);
        }

        $final = max(0, $final);

        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;

        return round($final, $decimals);
    }
}