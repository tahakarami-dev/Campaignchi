<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Skins;

use Msi\Campaignchi\Templates\Contracts\SliderTemplateInterface;
use Msi\Campaignchi\Templates\Skins\Concerns\BadgeTextTrait;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Glass" skin — glassmorphism style.
 *
 * A frosted, translucent card (backdrop-filter blur) floating over a soft
 * colorful gradient built from the instance's own primary/accent colors.
 * A modern, premium look for lifestyle/fashion-oriented stores.
 *
 * @package Msi\Campaignchi\Templates\Skins
 */
final class GlassTemplate implements SliderTemplateInterface
{
    use BadgeTextTrait;

    public function id(): string
    {
        return 'glass';
    }

    public function label(): string
    {
        return __('گلس — شیشه‌ای مدرن', 'campaignchi');
    }

    public function description(): string
    {
        return __('افکت شیشه‌ای (Glassmorphism) روی پس‌زمینه‌ی رنگی نرم — مناسب برندهای مدرن و لایف‌استایلی.', 'campaignchi');
    }

    public function previewIcon(): string
    {
        return 'ti-diamond';
    }

    public function previewGradient(): string
    {
        return 'linear-gradient(135deg,#a78bff 0%,#6C47FF 60%,#FF6B35 100%)';
    }

    public function defaultColors(): array
    {
        return ['primary' => '#6C47FF', 'accent' => '#FF6B35'];
    }

    public function renderSlide(array $product, array $settings, array $campaignMeta): string
    {
        $badge = $this->resolveBadgeText($product, $settings);

        ob_start();
        ?>
        <div class="swiper-slide cmc-slide cmc-slide--glass">
            <div class="cmc-slide--glass__inner">
                <a href="<?php echo esc_url($product['permalink']); ?>"
                   class="cmc-slide--glass__media"
                   style="background-image:url('<?php echo esc_url($product['image']); ?>')"></a>

                <?php if ($badge !== ''): ?>
                    <span class="cmc-slide--glass__badge"><?php echo esc_html($badge); ?></span>
                <?php endif; ?>

                <a href="<?php echo esc_url($product['permalink']); ?>" class="cmc-slide--glass__name">
                    <?php echo esc_html($product['name']); ?>
                </a>

                <div class="cmc-slide--glass__price">
                    <?php echo $product['price_html']; // phpcs:ignore -- WooCommerce-escaped price HTML ?>
                </div>

                <a href="<?php echo esc_url($product['permalink']); ?>" class="cmc-slide--glass__cta">
                    <?php echo esc_html($settings['cta_text']); ?>
                </a>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
