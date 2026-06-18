<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Services;

use Msi\Campaignchi\Templates\TemplateRegistry;

/**
 * Slider Settings Service
 *
 * Owns the entire "design & behavior" settings stack for the Campaign
 * Slider feature, resolved in three layers, each one able to override the
 * one before it:
 *
 *   1. hardcodedDefaults()   — safe fallback values, always available.
 *   2. global appearance     — admin-wide defaults (Appearance page),
 *                               stored in the `cmc_slider_global_settings`
 *                               option. Changing a value here retroactively
 *                               updates every slider that never explicitly
 *                               overrode that specific option.
 *   3. preset / instance      — a saved slider preset's own overrides, and
 *      overrides                then (on top of that) any ad-hoc attribute
 *                               passed directly on a shortcode or Elementor
 *                               widget instance.
 *
 * This is what guarantees the admin Templates page, the shortcode, and the
 * Elementor widget all end up rendering an IDENTICAL slider for the same
 * inputs — they all funnel through resolve() before ever reaching
 * SliderRenderer.
 *
 * @package Msi\Campaignchi\Templates\Services
 */
class SliderSettingsService
{
    private const GLOBAL_OPTION  = 'cmc_slider_global_settings';
    private const ENABLED_OPTION = 'cmc_slider_enabled_templates';

    /** Boolean-typed setting keys — kept in one place so sanitize()/normalize() agree on the list. */
    private const BOOLEAN_KEYS = [
        'autoplay', 'loop', 'arrows', 'dots', 'show_countdown', 'show_stock', 'dark_mode', 'master_enabled',
        'classic_badge_enabled',
    ];

    /**
     * The absolute fallback layer. Every key the rest of the feature relies
     * on MUST have a value here, so resolve() can never return a "missing" setting.
     */
    public static function hardcodedDefaults(): array
    {
        return [
            'default_template' => 'flux',
            'limit'             => 8,
            'order'             => 'priority',
            'autoplay'          => true,
            'autoplay_speed'    => 4000,
            'loop'              => true,
            'arrows'            => true,
            'dots'              => true,
            'show_countdown'    => true,
            'show_stock'        => true,
            'primary_color'     => '#6C47FF',
            'accent_color'      => '#FF6B35',
            'radius'            => 16,
            'dark_mode'         => false,
            'cta_text'          => __('مشاهده محصول', 'campaignchi'),
            'badge_text'        => '', // empty = auto-generated "X% تخفیف"
            // Override text for the slider header's campaign-TYPE badge
            // (e.g. "فلش سیل" / "پیشنهاد شگفت‌انگیز"). Empty = fall back to
            // Campaign::typeLabel() automatically (see SliderRenderer).
            // NOT to be confused with `badge_text` above, which overrides
            // the per-product DISCOUNT badge shown on each individual slide.
            'type_badge_text'   => '',
            'title'             => '', // empty = use the campaign's own title
            'master_enabled'    => true,
            // Styling for the classic discount badge rendered by
            // PricingServiceProvider::renderBadge() on default WooCommerce
            // shop-loop cards and the single product page — independent
            // from the slider's own colors, since a site owner may want
            // a different accent for the catalog-wide badge than for the
            // slider widget.
            'classic_badge_enabled'    => true,
            'classic_badge_bg_color'   => '#FF6B35',
            'classic_badge_text_color' => '#FFFFFF',
        ];
    }

    /** Admin-wide defaults, merged on top of hardcodedDefaults(). */
    public function getGlobalSettings(): array
    {
        $stored = get_option(self::GLOBAL_OPTION, []);

        return array_merge(self::hardcodedDefaults(), is_array($stored) ? $stored : []);
    }

    /** @param array<string,mixed> $input Raw, unsanitized form/AJAX input. */
    public function saveGlobalSettings(array $input): array
    {
        $clean = $this->sanitize($input);
        update_option(self::GLOBAL_OPTION, $clean);

        return $clean;
    }

    /** @return string[] Ids of templates currently selectable for NEW sliders. Already-saved sliders are unaffected. */
    public function getEnabledTemplates(): array
    {
        $stored = get_option(self::ENABLED_OPTION, null);

        // Default state (option never saved yet): every known template is enabled.
        return is_array($stored) ? $stored : TemplateRegistry::ids();
    }

    /** @return string[] The updated list, so the caller can immediately reflect it in the UI. */
    public function setTemplateEnabled(string $templateId, bool $enabled): array
    {
        $list = array_values(array_diff($this->getEnabledTemplates(), [$templateId]));

        if ($enabled) {
            $list[] = $templateId;
        }

        $list = array_values(array_unique($list));
        update_option(self::ENABLED_OPTION, $list);

        return $list;
    }

    /**
     * Merge the full settings stack: hardcoded defaults -> global admin
     * defaults -> preset overrides -> ad-hoc instance overrides. Only
     * non-empty/non-null keys in each later layer override the previous one.
     *
     * @param array<string,mixed> $presetOverrides   A saved slider preset's stored "settings" blob, or [].
     * @param array<string,mixed> $instanceOverrides Ad-hoc shortcode/widget attribute overrides, or [].
     */
    public function resolve(array $presetOverrides = [], array $instanceOverrides = []): array
    {
        $resolved = $this->getGlobalSettings();

        foreach ([$this->sanitize($presetOverrides), $this->sanitize($instanceOverrides)] as $layer) {
            foreach ($layer as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Whitelist + type-coerce a raw settings array. Unknown keys are
     * silently dropped; this is the single chokepoint all user-controlled
     * slider settings (global form, builder modal, shortcode attributes,
     * Elementor controls) must pass through before being stored or used.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function sanitize(array $input): array
    {
        $out = [];

        if (isset($input['default_template']) && TemplateRegistry::has((string) $input['default_template'])) {
            $out['default_template'] = (string) $input['default_template'];
        }
        if (isset($input['title'])) {
            $out['title'] = sanitize_text_field((string) $input['title']);
        }
        if (isset($input['limit'])) {
            $out['limit'] = max(1, min(20, absint($input['limit'])));
        }
        if (isset($input['order'])) {
            $out['order'] = in_array($input['order'], ['priority', 'random', 'newest'], true) ? $input['order'] : 'priority';
        }
        if (isset($input['autoplay_speed'])) {
            $out['autoplay_speed'] = max(1000, min(15000, absint($input['autoplay_speed'])));
        }
        if (isset($input['primary_color'])) {
            $out['primary_color'] = $this->sanitizeHex((string) $input['primary_color'], '#6C47FF');
        }
        if (isset($input['accent_color'])) {
            $out['accent_color'] = $this->sanitizeHex((string) $input['accent_color'], '#FF6B35');
        }
        if (isset($input['radius'])) {
            $out['radius'] = max(0, min(40, absint($input['radius'])));
        }
        if (isset($input['cta_text'])) {
            $out['cta_text'] = sanitize_text_field((string) $input['cta_text']);
        }
        if (isset($input['badge_text'])) {
            $out['badge_text'] = sanitize_text_field((string) $input['badge_text']);
        }
        if (isset($input['type_badge_text'])) {
            $out['type_badge_text'] = sanitize_text_field((string) $input['type_badge_text']);
        }
        if (isset($input['classic_badge_bg_color'])) {
            $out['classic_badge_bg_color'] = $this->sanitizeHex((string) $input['classic_badge_bg_color'], '#FF6B35');
        }
        if (isset($input['classic_badge_text_color'])) {
            $out['classic_badge_text_color'] = $this->sanitizeHex((string) $input['classic_badge_text_color'], '#FFFFFF');
        }

        foreach (self::BOOLEAN_KEYS as $key) {
            if (isset($input[$key])) {
                $out[$key] = $this->toBool($input[$key]);
            }
        }

        return $out;
    }

    /**
     * Normalize a value coming from very different sources (raw HTML form
     * strings, shortcode attribute strings, Elementor's PHP booleans/yes-no
     * strings) into a strict boolean.
     */
    private function toBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'true', 'on'], true);
    }

    private function sanitizeHex(string $hex, string $fallback): string
    {
        $hex = trim($hex);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? $hex : $fallback;
    }
}