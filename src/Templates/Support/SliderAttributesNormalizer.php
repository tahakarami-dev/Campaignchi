<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Slider Attributes Normalizer
 *
 * Both the [campaignchi_slider] shortcode and the Elementor widget collect
 * "instance overrides" from completely different raw sources (shortcode
 * attribute strings vs. Elementor's saved control values), but both need
 * to feed the exact same shape of array into
 * SliderSettingsService::resolve(). This class is the single chokepoint
 * that extracts ONLY the known override keys from a raw input array,
 * silently dropping anything else (id/template/campaign are handled
 * separately by the caller, never as "settings overrides").
 *
 * Skipping null/empty values here is what allows resolve() to correctly
 * fall through to the preset/global/hardcoded layers for any key the
 * caller did not explicitly set — see SliderSettingsService::resolve().
 *
 * @package Msi\Campaignchi\Templates\Support
 */
final class SliderAttributesNormalizer
{
    /** @var string[] The only keys ever treated as slider "settings" overrides. */
    private const OVERRIDE_KEYS = [
        'limit', 'order', 'autoplay', 'autoplay_speed', 'loop', 'arrows', 'dots',
        'show_countdown', 'show_stock', 'primary_color', 'accent_color', 'radius',
        'dark_mode', 'cta_text', 'badge_text', 'type_badge_text', 'title',
    ];

    /**
     * @param array<string,mixed> $raw Raw attributes from a shortcode or Elementor widget settings array.
     * @return array<string,mixed> Only the known override keys, with null/empty values stripped.
     */
    public static function normalize(array $raw): array
    {
        $out = [];

        foreach (self::OVERRIDE_KEYS as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            $value = $raw[$key];

            // Keep explicit booleans/zero/"0" — only true "nothing was set" (null or
            // empty string) should be treated as "not specified" and skipped here.
            if ($value === null || $value === '') {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}