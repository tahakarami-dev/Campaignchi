<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Skins;

use Msi\Campaignchi\Templates\Contracts\SliderTemplateInterface;
use Msi\Campaignchi\Templates\Skins\Concerns\BadgeTextTrait;

/**
 * "Compact" skin — small, dense catalog-style card.
 *
 * Sized to fit more products on screen at once (small square image, tight
 * typography, icon-only CTA). Best for campaigns covering a large number
 * of eligible products where browsing more options at a glance matters
 * more than showcasing each one individually.
 *
 * @package Msi\Campaignchi\Templates\Skins
 */
final class CompactTemplate implements SliderTemplateInterface
{
    use BadgeTextTrait;

    public function id(): string
    {
        return 'compact';
    }

    public function label(): string
    {
        return __('کامپکت — متراکم فروشگاهی', 'campaignchi');
    }

    public function description(): string
    {
        return __('کارت کوچک و فشرده برای نمایش تعداد بیشتر محصول در یک نگاه — مناسب کمپین‌های پرمحصول.', 'campaignchi');
    }

    public function previewIcon(): string
    {
        return 'ti-grid-dots';
    }

    public function previewGradient(): string
    {
        return 'linear-gradient(135deg,#22c55e 0%,#16a34a 100%)';
    }

    public function defaultColors(): array
    {
        return ['primary' => '#6C47FF', 'accent' => '#22c55e'];
    }

    public function renderSlide(array $product, array $settings, array $campaignMeta): string
    {
        $badge = $this->resolveBadgeText($product, $settings);

        ob_start();
        ?>
        <div class="swiper-slide cmc-slide cmc-slide--compact">
            <a href="<?php echo esc_url($product['permalink']); ?>"
               class="cmc-slide--compact__media"
               style="background-image:url('<?php echo esc_url($product['image']); ?>')">
                <?php if ($badge !== ''): ?>
                    <span class="cmc-slide--compact__badge"><?php echo esc_html($badge); ?></span>
                <?php endif; ?>
            </a>

            <div class="cmc-slide--compact__body">
                <a href="<?php echo esc_url($product['permalink']); ?>" class="cmc-slide--compact__name">
                    <?php echo esc_html($product['name']); ?>
                </a>

                <div class="cmc-slide--compact__row">
                    <span class="cmc-slide--compact__price">
                        <?php echo $product['price_html']; // phpcs:ignore -- WooCommerce-escaped price HTML ?>
                    </span>
                    <a href="<?php echo esc_url($product['permalink']); ?>"
                       class="cmc-slide--compact__cta"
                       title="<?php echo esc_attr($settings['cta_text']); ?>">
                        <i class="ti ti-shopping-cart"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
