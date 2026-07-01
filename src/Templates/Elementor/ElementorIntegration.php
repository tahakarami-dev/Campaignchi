<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Elementor Integration
 *
 * Registers the "Campaign Slider" Elementor widget — but ONLY when
 * Elementor itself is active. The `did_action('elementor/loaded')` guard
 * means this class never touches `\Elementor\Widget_Base` unless that
 * base class actually exists, so sites without Elementor installed are
 * completely unaffected (no fatal error, no notice, nothing loaded).
 *
 * Campaignchi's Application::boot() runs on `plugins_loaded` priority 20,
 * while Elementor fires `elementor/loaded` from its own `plugins_loaded`
 * callback at the default priority 10 — so by the time this boot() runs,
 * Elementor (if present) has already finished loading. This ordering is
 * intentional and mirrors the existing WooCommerce dependency check in
 * the plugin's bootstrap file.
 *
 * No dependencies are injected here on purpose — see CampaignSliderWidget's
 * docblock for why a Widget_Base subclass must resolve its own services
 * lazily instead of via constructor injection.
 *
 * @package Msi\Campaignchi\Templates\Elementor
 */
final class ElementorIntegration
{
    public function boot(): void
    {
        if (!did_action('elementor/loaded')) {
            return;
        }

        add_action('elementor/elements/categories_registered', [$this, 'registerCategory']);

        // Elementor 3.5+ uses 'elementor/widgets/register'; older versions use
        // 'elementor/widgets/widgets_registered' with no callback argument.
        if (version_compare(defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '0', '3.5.0', '>=')) {
            add_action('elementor/widgets/register', [$this, 'registerWidget']);
        } else {
            add_action('elementor/widgets/widgets_registered', [$this, 'registerWidgetLegacy']);
        }
    }

    /** @param \Elementor\Elements_Manager $elementsManager */
    public function registerCategory($elementsManager): void
    {
        $elementsManager->add_category('campaignchi', [
            'title' => __('کمپین‌چی', 'campaignchi'),
            'icon'  => 'eicon-flash',
        ]);
    }

    /** @param \Elementor\Widgets_Manager $widgetsManager */
    public function registerWidget($widgetsManager): void
    {
        $widgetsManager->register(new CampaignSliderWidget());
    }

    /** Legacy (pre-3.5) Elementor widget registration — no callback argument supplied. */
    public function registerWidgetLegacy(): void
    {
        \Elementor\Plugin::instance()->widgets_manager->register(new CampaignSliderWidget());
    }
}