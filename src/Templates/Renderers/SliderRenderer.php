<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Renderers;

use Msi\Campaignchi\Templates\TemplateRegistry;

/**
 * Slider Renderer
 *
 * Produces the final HTML for one slider instance. This is the single
 * rendering chokepoint shared by the [campaignchi_slider] shortcode, the
 * Elementor widget, and the admin Templates page's live-preview AJAX
 * handler — guaranteeing all three are always pixel-identical for the
 * same resolved settings/data.
 *
 * The OUTER wrapper (header, type badge, countdown, Swiper container,
 * navigation, pagination) is always built here, identically, regardless
 * of which skin is active. Only the markup for each individual product
 * slide is delegated to the active SliderTemplateInterface implementation.
 *
 * @package Msi\Campaignchi\Templates\Renderers
 */
class SliderRenderer
{
    /**
     * @param array $settings Fully-resolved settings, see SliderSettingsService::resolve(). Must include 'template'.
     * @param array $data     Resolved render data, see CampaignSliderDataService::resolve().
     */
    public function render(array $settings, array $data): string
    {
        $template = TemplateRegistry::get((string) $settings['template']) ?? TemplateRegistry::get('flux');

        // Defensive guard: should be unreachable since callers always validate
        // the template id beforehand, but never let an unknown id hard-fail the page.
        if ($template === null) {
            return '';
        }

        $campaign     = $data['campaign'];
        $products     = $data['products'];
        $countdownIso = $data['countdown_iso'];

        $uid = wp_unique_id('cmc-slider-');

        $campaignMeta = [
            'title'      => !empty($settings['title']) ? $settings['title'] : $campaign->title,
            'type'       => $campaign->type,
            'type_label' => $campaign->typeLabel(),
        ];

        ob_start();
        ?>
        <div class="cmc-slider cmc-slider--<?php echo esc_attr($settings['template']); ?><?php echo !empty($settings['dark_mode']) ? ' cmc-slider--dark' : ''; ?>"
             id="<?php echo esc_attr($uid); ?>"
             style="--cmc-s-primary:<?php echo esc_attr($settings['primary_color']); ?>;--cmc-s-accent:<?php echo esc_attr($settings['accent_color']); ?>;--cmc-s-radius:<?php echo esc_attr((string) $settings['radius']); ?>px;"
             data-autoplay="<?php echo !empty($settings['autoplay']) ? '1' : '0'; ?>"
             data-autoplay-speed="<?php echo esc_attr((string) $settings['autoplay_speed']); ?>"
             data-loop="<?php echo !empty($settings['loop']) ? '1' : '0'; ?>"
             data-arrows="<?php echo !empty($settings['arrows']) ? '1' : '0'; ?>"
             data-dots="<?php echo !empty($settings['dots']) ? '1' : '0'; ?>">

            <div class="cmc-slider__head">
                <div class="cmc-slider__head-title-wrap">
                    <span class="cmc-slider__type-badge cmc-slider__type-badge--<?php echo esc_attr($campaignMeta['type']); ?>">
                        <i class="ti <?php echo $campaignMeta['type'] === 'flash_sale' ? 'ti-bolt' : 'ti-star'; ?>"></i>
                        <?php echo esc_html($campaignMeta['type_label']); ?>
                    </span>
                    <h3 class="cmc-slider__title"><?php echo esc_html($campaignMeta['title']); ?></h3>
                </div>

                <?php if (!empty($settings['show_countdown']) && $countdownIso): ?>
                    <div class="cmc-slider__countdown" data-cmc-countdown="<?php echo esc_attr($countdownIso); ?>">
                        <div class="cmc-cd-block"><span class="cmc-cd-num" data-u="d">00</span><span class="cmc-cd-label"><?php esc_html_e('روز', 'campaignchi'); ?></span></div>
                        <span class="cmc-cd-sep">:</span>
                        <div class="cmc-cd-block"><span class="cmc-cd-num" data-u="h">00</span><span class="cmc-cd-label"><?php esc_html_e('ساعت', 'campaignchi'); ?></span></div>
                        <span class="cmc-cd-sep">:</span>
                        <div class="cmc-cd-block"><span class="cmc-cd-num" data-u="m">00</span><span class="cmc-cd-label"><?php esc_html_e('دقیقه', 'campaignchi'); ?></span></div>
                        <span class="cmc-cd-sep">:</span>
                        <div class="cmc-cd-block"><span class="cmc-cd-num" data-u="s">00</span><span class="cmc-cd-label"><?php esc_html_e('ثانیه', 'campaignchi'); ?></span></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($settings['arrows'])): ?>
                    <div class="cmc-slider__nav">
                        <button type="button" class="cmc-slider__nav-btn cmc-slider__prev" aria-label="<?php esc_attr_e('قبلی', 'campaignchi'); ?>"><i class="ti ti-chevron-right"></i></button>
                        <button type="button" class="cmc-slider__nav-btn cmc-slider__next" aria-label="<?php esc_attr_e('بعدی', 'campaignchi'); ?>"><i class="ti ti-chevron-left"></i></button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="swiper cmc-slider__swiper">
                <div class="swiper-wrapper">
                    <?php foreach ($products as $product): ?>
                        <?php echo $template->renderSlide($product, $settings, $campaignMeta); // phpcs:ignore -- each skin escapes its own output ?>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($settings['dots'])): ?>
                    <div class="swiper-pagination"></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
