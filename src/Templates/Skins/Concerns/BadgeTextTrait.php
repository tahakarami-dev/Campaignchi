<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Skins\Concerns;

use Msi\Campaignchi\Helpers\JalaliHelper;

/**
 * BadgeTextTrait
 *
 * Shared by every skin so the "discount badge" rule stays identical no
 * matter which of the 5 templates is rendering: an explicit admin override
 * (settings['badge_text']) always wins; otherwise we fall back to an
 * auto-generated "X% تخفیف" label computed from this specific product's
 * own discount percentage (a fixed-amount campaign discount has a
 * different percent-off on every product, so this can never be
 * pre-computed once for the whole slider — see CampaignSliderDataService).
 *
 * @package Msi\Campaignchi\Templates\Skins\Concerns
 */
trait BadgeTextTrait
{
    /**
     * @param array $product  Resolved product data (must contain 'percent_off').
     * @param array $settings Resolved slider settings (must contain 'badge_text').
     */
    protected function resolveBadgeText(array $product, array $settings): string
    {
        if (!empty($settings['badge_text'])) {
            return (string) $settings['badge_text'];
        }

        $percentOff = (int) ($product['percent_off'] ?? 0);

        if ($percentOff > 0) {
            return JalaliHelper::toPersianNums((string) $percentOff) . '٪ ' . __('تخفیف', 'campaignchi');
        }

        return '';
    }
}
