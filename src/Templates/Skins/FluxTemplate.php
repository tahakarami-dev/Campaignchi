<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Skins;

use Msi\Campaignchi\Templates\Contracts\SliderTemplateInterface;
use Msi\Campaignchi\Templates\Skins\Concerns\BadgeTextTrait;

/**
 * "Flux" skin — energetic neon/gradient style.
 *
 * Full-bleed product image with a dark gradient overlay holding the info
 * block, a glowing gradient discount badge, and a thin stock-urgency bar.
 * Best suited for urgent, high-energy flash-sale campaigns.
 *
 * @package Msi\Campaignchi\Templates\Skins
 */
final class FluxTemplate implements SliderTemplateInterface
{
    use BadgeTextTrait;

    public function id(): string
    {
        return 'flux';
    }

    public function label(): string
    {
        return __('فلاکس — نئون پرانرژی', 'campaignchi');
    }

    public function description(): string
    {
        return __('گرادیان پرانرژی با درخشش نئون و تصویر تمام‌قد محصول — مناسب فلش‌سیل‌های فوری.', 'campaignchi');
    }

    public function previewIcon(): string
    {
        return 'ti-bolt';
    }

    public function previewGradient(): string
    {
        return 'linear-gradient(135deg,#6C47FF 0%,#FF6B35 100%)';
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
        <div class="swiper-slide cmc-slide cmc-slide--flux">
            <a href="<?php echo esc_url($product['permalink']); ?>"
               class="cmc-slide--flux__media"
               style="background-image:url('<?php echo esc_url($product['image']); ?>')">
                <?php if ($badge !== ''): ?>
                    <span class="cmc-slide--flux__badge">
                        <i class="ti ti-bolt"></i> <?php echo esc_html($badge); ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="cmc-slide--flux__body">
                <a href="<?php echo esc_url($product['permalink']); ?>" class="cmc-slide--flux__name">
                    <?php echo esc_html($product['name']); ?>
                </a>

                <div class="cmc-slide--flux__price">
                    <?php echo $product['price_html']; // phpcs:ignore -- WooCommerce-escaped price HTML ?>
                </div>

                <?php if (!empty($settings['show_stock']) && !empty($product['stock'])): ?>
                    <div class="cmc-slide--flux__stock-track">
                        <div class="cmc-slide--flux__stock-fill" style="width:<?php echo (int) $product['stock']['percent']; ?>%"></div>
                    </div>
                <?php endif; ?>

                <a href="<?php echo esc_url($product['permalink']); ?>" class="cmc-slide--flux__cta">
                    <?php echo esc_html($settings['cta_text']); ?>
                    <i class="ti ti-arrow-left"></i>
                </a>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
