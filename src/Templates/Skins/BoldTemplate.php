<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Skins;

use Msi\Campaignchi\Helpers\JalaliHelper;
use Msi\Campaignchi\Templates\Contracts\SliderTemplateInterface;
use Msi\Campaignchi\Templates\Skins\Concerns\BadgeTextTrait;

/**
 * "Bold" skin — high-contrast banner-style card.
 *
 * Solid accent-color background, product image on one side, and an
 * oversized percent-off number as the focal point. Best for aggressive,
 * high-urgency promotional placements (e.g. homepage hero banners).
 *
 * @package Msi\Campaignchi\Templates\Skins
 */
final class BoldTemplate implements SliderTemplateInterface
{
    use BadgeTextTrait;

    public function id(): string
    {
        return 'bold';
    }

    public function label(): string
    {
        return __('بولد — بنری پررنگ', 'campaignchi');
    }

    public function description(): string
    {
        return __('کارت بنری با رنگ تخت و عدد درصد تخفیف بزرگ — مناسب کمپین‌های پرتاکید و تبلیغاتی.', 'campaignchi');
    }

    public function previewIcon(): string
    {
        return 'ti-percentage';
    }

    public function previewGradient(): string
    {
        return 'linear-gradient(135deg,#FF6B35 0%,#e85e2a 100%)';
    }

    public function defaultColors(): array
    {
        return ['primary' => '#FF6B35', 'accent' => '#6C47FF'];
    }

    public function renderSlide(array $product, array $settings, array $campaignMeta): string
    {
        $percentOff = (int) ($product['percent_off'] ?? 0);

        ob_start();
        ?>
        <div class="swiper-slide cmc-slide cmc-slide--bold">
            <a href="<?php echo esc_url($product['permalink']); ?>"
               class="cmc-slide--bold__media"
               style="background-image:url('<?php echo esc_url($product['image']); ?>')"></a>

            <div class="cmc-slide--bold__body">
                <div>
                    <?php if ($percentOff > 0): ?>
                        <div class="cmc-slide--bold__percent">
                            <?php echo esc_html(JalaliHelper::toPersianNums((string) $percentOff)); ?>٪
                            <span><?php esc_html_e('تخفیف', 'campaignchi'); ?></span>
                        </div>
                    <?php endif; ?>

                    <a href="<?php echo esc_url($product['permalink']); ?>" class="cmc-slide--bold__name">
                        <?php echo esc_html($product['name']); ?>
                    </a>

                    <div class="cmc-slide--bold__price">
                        <?php echo $product['price_html']; // phpcs:ignore -- WooCommerce-escaped price HTML ?>
                    </div>
                </div>

                <a href="<?php echo esc_url($product['permalink']); ?>" class="cmc-slide--bold__cta">
                    <?php echo esc_html($settings['cta_text']); ?>
                    <i class="ti ti-arrow-left"></i>
                </a>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
