<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates;

use Msi\Campaignchi\Templates\Contracts\SliderTemplateInterface;
use Msi\Campaignchi\Templates\Skins\BoldTemplate;
use Msi\Campaignchi\Templates\Skins\CompactTemplate;
use Msi\Campaignchi\Templates\Skins\FluxTemplate;
use Msi\Campaignchi\Templates\Skins\GlassTemplate;
use Msi\Campaignchi\Templates\Skins\MinimalTemplate;

/**
 * Template Registry
 *
 * Single source of truth for "which slider skins exist". Adding a 6th
 * skin in the future means: implement SliderTemplateInterface, then add
 * one line to load() — every other part of the feature (admin gallery,
 * shortcode validation, Elementor controls, settings sanitization)
 * automatically picks it up because they all read from this registry
 * instead of hardcoding ids.
 *
 * Note: this registry only knows which skins EXIST. Whether a skin is
 * currently *selectable* for new sliders is a separate, persisted concern
 * — see SliderSettingsService::getEnabledTemplates(). Disabling a skin
 * never breaks sliders that already use it.
 *
 * @package Msi\Campaignchi\Templates
 */
final class TemplateRegistry
{
    /** @var array<string, SliderTemplateInterface>|null Lazily-built, memoized for the request. */
    private static ?array $templates = null;

    /** @return array<string, SliderTemplateInterface> */
    public static function all(): array
    {
        return self::load();
    }

    /** @return string[] All known template ids, in display order. */
    public static function ids(): array
    {
        return array_keys(self::load());
    }

    public static function get(string $id): ?SliderTemplateInterface
    {
        return self::load()[$id] ?? null;
    }

    public static function has(string $id): bool
    {
        return isset(self::load()[$id]);
    }

    /** @return array<string, SliderTemplateInterface> */
    private static function load(): array
    {
        if (self::$templates !== null) {
            return self::$templates;
        }

        $instances = [
            new FluxTemplate(),
            new MinimalTemplate(),
            new GlassTemplate(),
            new BoldTemplate(),
            new CompactTemplate(),
        ];

        self::$templates = [];
        foreach ($instances as $template) {
            self::$templates[$template->id()] = $template;
        }

        return self::$templates;
    }
}
