<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Shortcode;

use Msi\Campaignchi\Templates\Repositories\SliderRepository;
use Msi\Campaignchi\Templates\Services\CampaignSliderDataService;
use Msi\Campaignchi\Templates\Services\SliderSettingsService;
use Msi\Campaignchi\Templates\Renderers\SliderRenderer;
use Msi\Campaignchi\Templates\Support\SliderAttributesNormalizer;
use Msi\Campaignchi\Templates\TemplateRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign Slider Shortcode
 *
 * Registers `[campaignchi_slider]`. Supports two usage styles:
 *
 *   1. Saved preset:  [campaignchi_slider id="3"]
 *      Loads template/campaign/settings from a SliderRepository preset.
 *      Any inline attribute provided alongside `id` still overrides the
 *      preset's own value for that one field (instance > preset).
 *
 *   2. Ad-hoc inline: [campaignchi_slider template="glass" campaign="12" limit="6" ...]
 *      No preset involved — every value either comes from the attribute
 *      or falls through to the global appearance defaults.
 *
 * This class never talks to WooCommerce or builds HTML itself — it only
 * resolves "which settings + which data", then hands both off to
 * SliderRenderer, the single rendering chokepoint shared with the
 * Elementor widget and the admin live-preview AJAX handler.
 *
 * @package Msi\Campaignchi\Templates\Shortcode
 */
class CampaignSliderShortcode
{
    public const TAG = 'campaignchi_slider';

    public function __construct(
        private SliderSettingsService $settings,
        private SliderRepository $sliders,
        private CampaignSliderDataService $dataService,
        private SliderRenderer $renderer
    ) {}

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /**
     * @param array<string,mixed>|string $atts Raw shortcode attributes from WordPress.
     */
    public function render($atts): string
    {
        $global = $this->settings->getGlobalSettings();

        // The feature-wide master switch (Appearance page) — when off, the
        // shortcode renders nothing at all, anywhere on the site.
        if (empty($global['master_enabled'])) {
            return $this->adminOnlyNotice(
                __('قابلیت اسلایدر کمپین از بخش ظاهر غیرفعال شده است.', 'campaignchi')
            );
        }

        $atts = shortcode_atts(
            [
                'id'             => 0,
                'template'       => '',
                'campaign'       => 0,
                // Every customizable field defaults to null (NOT a concrete value) so
                // SliderAttributesNormalizer can tell "not specified" apart from an
                // explicit value — see SliderAttributesNormalizer::normalize().
                'limit'             => null,
                'order'             => null,
                'autoplay'          => null,
                'autoplay_speed'    => null,
                'loop'              => null,
                'arrows'            => null,
                'dots'              => null,
                'show_countdown'    => null,
                'show_stock'        => null,
                'primary_color'     => null,
                'accent_color'      => null,
                'radius'            => null,
                'dark_mode'         => null,
                'cta_text'          => null,
                'badge_text'        => null,
                // Overrides the slider header's campaign-type badge text
                // (e.g. [campaignchi_slider type_badge_text="Today's Special"]).
                'type_badge_text'   => null,
                'title'             => null,
            ],
            $atts,
            self::TAG
        );

        $presetSettings = [];
        $template       = (string) $atts['template'];
        $campaignId     = absint($atts['campaign']) ?: null;

        $presetId = absint($atts['id']);
        if ($presetId > 0) {
            $preset = $this->sliders->find($presetId);

            if ($preset === null) {
                return $this->adminOnlyNotice(
                    /* translators: %d: saved slider preset id. */
                    sprintf(__('اسلایدر ذخیره‌شده با شناسه %d پیدا نشد.', 'campaignchi'), $presetId)
                );
            }

            // Preset values are the base; explicit shortcode attributes (if any) still win.
            $template       = $template !== '' ? $template : $preset['template'];
            $campaignId     = $campaignId ?? $preset['campaign_id'];
            $presetSettings = $preset['settings'];
        }

        if ($template === '' || !TemplateRegistry::has($template)) {
            $template = TemplateRegistry::has((string) $global['default_template'])
                ? (string) $global['default_template']
                : 'flux';
        }

        $instanceOverrides = SliderAttributesNormalizer::normalize($atts);
        $resolvedSettings  = $this->settings->resolve($presetSettings, $instanceOverrides);
        $resolvedSettings['template'] = $template;

        $data = $this->dataService->resolve(
            $campaignId,
            (int) $resolvedSettings['limit'],
            (string) $resolvedSettings['order']
        );

        if ($data === null) {
            return $this->adminOnlyNotice(
                __('کمپین فعالی برای نمایش در این اسلایدر یافت نشد.', 'campaignchi')
            );
        }

        return $this->renderer->render($resolvedSettings, $data);
    }

    /**
     * A frontend-safe notice shown ONLY to logged-in admins (e.g. "no live
     * campaign", "preset not found"), so site visitors never see a broken
     * empty box — they simply see nothing.
     *
     * Inline-styled on purpose: the admin CSS framework (cmc-alert, ...)
     * is never enqueued on the public frontend, so reusing those classes
     * here would render completely unstyled.
     */
    private function adminOnlyNotice(string $message): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        return sprintf(
            '<div style="margin:16px 0;padding:14px 18px;border:1px solid #f5c2c7;background:#fff3f4;color:#7a1f24;border-radius:10px;font-family:sans-serif;font-size:14px;direction:rtl;">%s <strong>(%s)</strong></div>',
            esc_html($message),
            esc_html__('فقط برای مدیر سایت نمایش داده می‌شود', 'campaignchi')
        );
    }
}