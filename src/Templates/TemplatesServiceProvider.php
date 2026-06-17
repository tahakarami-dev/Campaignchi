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
 * @package Msi\Campaignchi\Templates
 */
class TemplatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SliderSettingsService::class, static fn () => new SliderSettingsService());

        $this->app->singleton(SliderRepository::class, static fn () => new SliderRepository());

        $this->app->singleton(SliderRenderer::class, static fn () => new SliderRenderer());

        $this->app->singleton(
            CampaignSliderDataService::class,
            static fn ($app) => new CampaignSliderDataService(
                $app->make(CampaignRepository::class),
                $app->make(CampaignProductResolver::class)
            )
        );

        $this->app->singleton(
            CampaignSliderShortcode::class,
            static fn ($app) => new CampaignSliderShortcode(
                $app->make(SliderSettingsService::class),
                $app->make(SliderRepository::class),
                $app->make(CampaignSliderDataService::class),
                $app->make(SliderRenderer::class)
            )
        );

        $this->app->singleton(
            TemplatesAjaxController::class,
            static fn ($app) => new TemplatesAjaxController(
                $app->make(SliderSettingsService::class),
                $app->make(SliderRepository::class),
                $app->make(CampaignSliderDataService::class),
                $app->make(SliderRenderer::class),
                $app->make(CampaignRepository::class)
            )
        );

        $this->app->singleton(
            ElementorIntegration::class,
            static fn ($app) => new ElementorIntegration(
                $app->make(SliderSettingsService::class),
                $app->make(SliderRepository::class),
                $app->make(CampaignSliderDataService::class),
                $app->make(SliderRenderer::class),
                $app->make(CampaignRepository::class)
            )
        );
    }

    public function boot(): void
    {
        $this->app->make(CampaignSliderShortcode::class)->register();
        $this->app->make(TemplatesAjaxController::class)->register();
        $this->app->make(ElementorIntegration::class)->boot();

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

        wp_enqueue_style('cmc-slider', CAMPAIGNCHI_URL . 'assets/css/slider.css', ['cmc-swiper'], CAMPAIGNCHI_VERSION);
        wp_enqueue_script('cmc-frontend-slider', CAMPAIGNCHI_URL . 'assets/js/frontend-slider.js', ['cmc-swiper'], CAMPAIGNCHI_VERSION, true);
    }
}
