<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Skins;

use Msi\Campaignchi\Templates\Contracts\SliderTemplateInterface;
use Msi\Campaignchi\Templates\Skins\Concerns\BadgeTextTrait;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Minimal" skin — clean, light commercial card matching the admin panel's
 * own design tokens (white surface, soft border, brand-purple accents).
 *
 * Best for stores that want a calm, professional, low-distraction look.
 *
 * @package Msi\Campaignchi\Templates\Skins
 */
final class MinimalTemplate implements SliderTemplateInterface
{
    use BadgeTextTrait;

    public function id(): string
    {
        return 'minimal';
    }

    public function label(): string
    {
        return __('مینیمال — تجاری و تمیز', 'campaignchi');
    }

    public function description(): string
    {
        return __('کارت سفید و تمیز با جزئیات مینیمال — مناسب فروشگاه‌هایی با هویت بصری آرام و حرفه‌ای.', 'campaignchi');
    }

    public function previewIcon(): string
    {
        return 'ti-square-rounded';
    }

    public function previewGradient(): string
    {
        return 'linear-gradient(135deg,#f3f0ff 0%,#e9e4ff 100%)';
    }

    public function defaultColors(): array
    {
        return ['primary' => '#6C47FF', 'accent' => '#9ca3af'];
    }

    public function renderSlide(array $product, array $settings, array $campaignMeta): string
    {
        $badge = $this->resolveBadgeText($product, $settings);

        ob_start();
        ?>
        <div class="swiper-slide cmc-slide cmc-slide--minimal">
            <a href="<?php echo esc_url($product['permalink']); ?>"
               class="cmc-slide--minimal__media"
               style="background-image:url('<?php echo esc_url($product['image']); ?>')">
                <?php if ($badge !== ''): ?>
                    <span class="cmc-slide--minimal__badge"><?php echo esc_html($badge); ?></span>
                <?php endif; ?>
            </a>

            <div class="cmc-slide--minimal__body">
                <a href="<?php echo esc_url($product['permalink']); ?>" class="cmc-slide--minimal__name">
                    <?php echo esc_html($product['name']); ?>
                </a>

                <div class="cmc-slide--minimal__price">
                    <?php echo $product['price_html']; // phpcs:ignore -- WooCommerce-escaped price HTML ?>
                </div>

                <a href="<?php echo esc_url($product['permalink']); ?>" class="cmc-slide--minimal__cta">
                    <?php echo esc_html($settings['cta_text']); ?>
                </a>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
