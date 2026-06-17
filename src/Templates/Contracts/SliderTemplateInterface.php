<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Contracts;

/**
 * Contract every slider "skin" (one of the 5 built-in designs) must
 * implement.
 *
 * TemplateRegistry holds exactly one instance of each implementation.
 * SliderRenderer always renders the shared wrapper (header, type badge,
 * countdown, Swiper container, navigation, pagination) itself — only the
 * markup for ONE product slide is delegated to the active skin via
 * renderSlide(). This keeps the slider *engine* centralized (one place to
 * fix bugs, one place to keep the Elementor widget and the shortcode in
 * sync) while still letting every skin look completely different.
 *
 * @package Msi\Campaignchi\Templates\Contracts
 */
interface SliderTemplateInterface
{
    /** Stable, machine-readable identifier (e.g. "flux"). Persisted in the DB and in shortcodes — never change an existing id. */
    public function id(): string;

    /** Human-readable name shown in the admin gallery and in every template picker (shortcode builder, Elementor widget). */
    public function label(): string;

    /** One-line description shown under the gallery card. */
    public function description(): string;

    /** Tabler icon suffix (e.g. "ti-bolt") used on the gallery swatch. */
    public function previewIcon(): string;

    /** CSS `background` value used to paint the gallery card's swatch preview. */
    public function previewGradient(): string;

    /**
     * Suggested default colors for this skin — used to pre-fill the
     * builder form the first time a user picks this template.
     *
     * @return array{primary: string, accent: string}
     */
    public function defaultColors(): array;

    /**
     * Render exactly one product's slide markup (a single `.swiper-slide`
     * element). The returned string MUST already be fully escaped — it is
     * echoed verbatim by SliderRenderer.
     *
     * @param array $product      Resolved product data, see CampaignSliderDataService::mapProduct().
     * @param array $settings     Fully-resolved slider settings (colors, cta text, toggles...).
     * @param array $campaignMeta Campaign title/type/type_label (see SliderRenderer::render()).
     */
    public function renderSlide(array $product, array $settings, array $campaignMeta): string;
}
