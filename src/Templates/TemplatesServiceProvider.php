<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates;

use Msi\Campaignchi\Campaign\Pricing\CampaignProductResolver;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Core\ServiceProvider;
use Msi\Campaignchi\Templates\Admin\TemplatesAjaxController;
use Msi\Campaignchi\Templates\Elementor\ElementorIntegration;
use Msi\Campaignchi\Templates\Renderers\SliderRenderer;
use Msi\Campaignchi\Templates\Repositories\SliderRepository;
use Msi\Campaignchi\Templates\Services\CampaignSliderDataService;
use Msi\Campaignchi\Templates\Services\SliderSettingsService;
use Msi\Campaignchi\Templates\Shortcode\CampaignSliderShortcode;

/**
 * Templates Service Provider
 *
 * Wires up the entire "Campaign Slider" feature: the 5 selectable
 * templates, the shortcode, the Elementor widget, the admin AJAX
 * controller, and the unconditional frontend asset enqueue.
 *
 * NOTE: the DI container is exposed by the abstract ServiceProvider base
 * as `$this->container` (see Core\ServiceProvider::__construct()) — NOT
 * `$this->app`. Every other provider in this codebase (AdminServiceProvider,
 * PricingServiceProvider, AnalyticsServiceProvider) uses `$this->container`,
 * so this provider follows the exact same convention.
 *
 * @package Msi\Campaignchi\Templates
 */
class TemplatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SliderSettingsService::class, static fn () => new SliderSettingsService());

        $this->container->singleton(SliderRepository::class, static fn () => new SliderRepository());

        $this->container->singleton(SliderRenderer::class, static fn () => new SliderRenderer());

        $this->container->singleton(
            CampaignSliderDataService::class,
            static fn ($c) => new CampaignSliderDataService(
                $c->make(CampaignRepository::class),
                $c->make(CampaignProductResolver::class)
            )
        );

        $this->container->singleton(
            CampaignSliderShortcode::class,
            static fn ($c) => new CampaignSliderShortcode(
                $c->make(SliderSettingsService::class),
                $c->make(SliderRepository::class),
                $c->make(CampaignSliderDataService::class),
                $c->make(SliderRenderer::class)
            )
        );

        $this->container->singleton(
            TemplatesAjaxController::class,
            static fn ($c) => new TemplatesAjaxController(
                $c->make(SliderSettingsService::class),
                $c->make(SliderRepository::class),
                $c->make(CampaignSliderDataService::class),
                $c->make(SliderRenderer::class),
                $c->make(CampaignRepository::class)
            )
        );

        // ElementorIntegration takes no dependencies: CampaignSliderWidget
        // resolves its own services lazily via Application::getInstance()
        // — see the class docblock for why constructor injection is NOT
        // safe for an \Elementor\Widget_Base subclass.
        $this->container->singleton(
            ElementorIntegration::class,
            static fn () => new ElementorIntegration()
        );
    }

    public function boot(): void
    {
        $this->container->make(CampaignSliderShortcode::class)->register();
        $this->container->make(TemplatesAjaxController::class)->register();
        $this->container->make(ElementorIntegration::class)->boot();

        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets']);
    }

    /**
     * Loaded unconditionally on every frontend request.
     *
     * Shortcodes and Elementor widgets render their markup inside
     * `the_content`, which fires AFTER `wp_head` has already printed all
     * enqueued styles — so there is no reliable way to "detect a slider
     * is present" early enough to conditionally enqueue. The files here
     * are small, so loading them site-wide is the simplest correct
     * approach (the same convention FrontendServiceProvider already uses
     * for frontend.css).
     */
    public function enqueueFrontendAssets(): void
    {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style('cmc-icons', 'https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css', [], null);
        wp_enqueue_style('cmc-font-vazirmatn', 'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap', [], null);

        wp_enqueue_style('cmc-swiper', 'https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.css', [], '8.0.0');
        wp_enqueue_script('cmc-swiper', 'https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.js', [], '8.0.0', true);

        // ⚠️ ISOLATION FIX: Swiper's UMD bundle always defines the SAME
        // global `window.Swiper`, regardless of who loaded it. Many WP
        // themes/plugins bundle their own copy of Swiper too — if theirs
        // loads after ours, `window.Swiper` silently becomes THEIR version
        // (possibly a different, incompatible one) for the rest of the
        // page, and vice versa. Capturing our own reference immediately
        // after OUR script tag executes, into a private global
        // (`window.CMCSwiperLib`), means frontend-slider.js never depends
        // on whatever `window.Swiper` happens to point to later — it is
        // fully decoupled from any other Swiper copy on the page.
        wp_add_inline_script('cmc-swiper', 'window.CMCSwiperLib = window.CMCSwiperLib || window.Swiper;', 'after');

        // NOTE: this plugin's bootstrap (campaignchi.php) defines CMC_URL /
        // CMC_VERSION — there is no CAMPAIGNCHI_URL/CAMPAIGNCHI_VERSION
        // constant anywhere in the codebase. Using the real constants here.
        wp_enqueue_style('cmc-slider', CMC_URL . 'assets/css/slider.css', ['cmc-swiper'], CMC_VERSION);
        wp_enqueue_script('cmc-frontend-slider', CMC_URL . 'assets/js/frontend-slider.js', ['cmc-swiper'], CMC_VERSION, true);
    }
}