<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Core\Application;
use Msi\Campaignchi\Templates\Repositories\SliderRepository;
use Msi\Campaignchi\Templates\Renderers\SliderRenderer;
use Msi\Campaignchi\Templates\Services\CampaignSliderDataService;
use Msi\Campaignchi\Templates\Services\SliderSettingsService;
use Msi\Campaignchi\Templates\Support\SliderAttributesNormalizer;
use Msi\Campaignchi\Templates\TemplateRegistry;

/**
 * Campaign Slider — Elementor Widget
 *
 * Mirrors the [campaignchi_slider] shortcode exactly: a "use saved
 * preset" dropdown (populated from SliderRepository), or full inline
 * controls when no preset is selected. Both paths funnel through the
 * exact same SliderSettingsService::resolve() + SliderRenderer::render()
 * used by the shortcode and the admin live-preview, guaranteeing pixel
 * parity between the Elementor editor, its frontend output, and the
 * shortcode.
 *
 * IMPORTANT — no constructor dependency injection here, on purpose:
 * Elementor's own `Element_Base::get_new_instance($data, $args)` creates
 * a FRESH instance of this exact class via `new static($data, $args)`
 * every time it actually renders this widget on a real page — passing
 * only those two arguments. If this constructor required any additional
 * typed parameters (as an earlier version of this class did), that
 * internal Elementor call would fail with "Too few arguments" the
 * moment the widget is placed on a page (registration alone would still
 * succeed, since WE control that one instantiation — only Elementor's
 * own internal re-instantiation breaks). Every service this widget needs
 * is therefore resolved lazily, on demand, via the Application container
 * — the same pattern AppearancePage/TemplatesPage already use for the
 * exact same reason (AdminRouter also instantiates pages with `new
 * $pageClass()`, no constructor arguments).
 *
 * @package Msi\Campaignchi\Templates\Elementor
 */
final class CampaignSliderWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'campaignchi_slider';
    }

    public function get_title(): string
    {
        return __('اسلایدر کمپین کمپین‌چی', 'campaignchi');
    }

    public function get_icon(): string
    {
        return 'eicon-slider-push';
    }

    public function get_categories(): array
    {
        return ['campaignchi'];
    }

    public function get_keywords(): array
    {
        return ['campaign', 'slider', 'flash sale', 'کمپین', 'اسلایدر', 'فلش سیل', 'تخفیف'];
    }

    protected function register_controls(): void
    {
        $this->registerSourceControls();
        $this->registerContentControls();
        $this->registerStyleControls();
    }

    /** Controls for choosing WHAT to show: a saved preset, or an ad-hoc template + campaign. */
    private function registerSourceControls(): void
    {
        $this->start_controls_section('cmc_section_source', [
            'label' => __('منبع داده', 'campaignchi'),
        ]);

        $presetOptions = ['0' => __('— پیکربندی دستی (بدون پریست) —', 'campaignchi')];
        foreach ($this->sliderRepository()->all() as $preset) {
            $presetOptions[(string) $preset['id']] = $preset['title'];
        }

        $this->add_control('preset_id', [
            'label'   => __('استفاده از اسلایدر ذخیره‌شده', 'campaignchi'),
            'type'    => Controls_Manager::SELECT,
            'default' => '0',
            'options' => $presetOptions,
            'description' => __('پریست‌ها از صفحه «قالب‌ها» در پنل مدیریت ساخته می‌شوند.', 'campaignchi'),
        ]);

        $templateOptions = [];
        foreach (TemplateRegistry::all() as $template) {
            $templateOptions[$template->id()] = $template->label();
        }

        $this->add_control('template', [
            'label'     => __('قالب', 'campaignchi'),
            'type'      => Controls_Manager::SELECT,
            'default'   => 'flux',
            'options'   => $templateOptions,
            'condition' => ['preset_id' => '0'],
        ]);

        $campaignOptions = ['0' => __('— انتخاب خودکار (بالاترین اولویت) —', 'campaignchi')];
        foreach ($this->campaignRepository()->getNonDraftCampaigns() as $campaign) {
            $campaignOptions[(string) $campaign->id] = $campaign->title;
        }

        $this->add_control('campaign_id', [
            'label'     => __('کمپین', 'campaignchi'),
            'type'      => Controls_Manager::SELECT,
            'default'   => '0',
            'options'   => $campaignOptions,
            'condition' => ['preset_id' => '0'],
        ]);

        $this->end_controls_section();
    }

    /** Controls for product count/order/behavior toggles — all hidden once a preset is chosen. */
    private function registerContentControls(): void
    {
        $this->start_controls_section('cmc_section_content', [
            'label'     => __('محتوا و رفتار', 'campaignchi'),
            'condition' => ['preset_id' => '0'],
        ]);

        $this->add_control('limit', [
            'label'   => __('تعداد محصولات', 'campaignchi'),
            'type'    => Controls_Manager::NUMBER,
            'min'     => 1,
            'max'     => 20,
            'default' => 8,
        ]);

        $this->add_control('order', [
            'label'   => __('ترتیب نمایش', 'campaignchi'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'priority',
            'options' => [
                'priority' => __('اولویت پیش‌فرض کمپین', 'campaignchi'),
                'newest'   => __('جدیدترین', 'campaignchi'),
                'random'   => __('تصادفی', 'campaignchi'),
            ],
        ]);

        $this->add_control('title', [
            'label'       => __('عنوان دلخواه', 'campaignchi'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => __('خالی = عنوان خود کمپین', 'campaignchi'),
        ]);

        $this->add_control('autoplay', [
            'label'        => __('پخش خودکار', 'campaignchi'),
            'type'         => Controls_Manager::SWITCHER,
            'default'      => 'yes',
        ]);

        $this->add_control('autoplay_speed', [
            'label'     => __('سرعت پخش خودکار (میلی‌ثانیه)', 'campaignchi'),
            'type'      => Controls_Manager::NUMBER,
            'min'       => 1000,
            'max'       => 15000,
            'step'      => 500,
            'default'   => 4000,
            'condition' => ['autoplay' => 'yes'],
        ]);

        $this->add_control('loop', [
            'label'   => __('چرخه پیوسته', 'campaignchi'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('arrows', [
            'label'   => __('فلش‌های ناوبری', 'campaignchi'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('dots', [
            'label'   => __('نقاط ناوبری', 'campaignchi'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('show_countdown', [
            'label'   => __('نمایش شمارش معکوس', 'campaignchi'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('show_stock', [
            'label'   => __('نمایش نوار موجودی', 'campaignchi'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('cta_text', [
            'label'       => __('متن دکمه CTA', 'campaignchi'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => __('مشاهده محصول', 'campaignchi'),
        ]);

        $this->add_control('badge_text', [
            'label'       => __('متن بج تخفیف (دلخواه)', 'campaignchi'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => __('خالی = درصد تخفیف به‌صورت خودکار', 'campaignchi'),
        ]);

        // ⚠️ NEW: lets the campaign-type badge shown in the slider header
        // (e.g. "فلش سیل" / "پیشنهاد شگفت‌انگیز") be customized per widget
        // instance, same as the shortcode's `type_badge_text` attribute.
        $this->add_control('type_badge_text', [
            'label'       => __('متن بج نوع کمپین (دلخواه)', 'campaignchi'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => __('خالی = نام نوع کمپین به‌صورت خودکار', 'campaignchi'),
        ]);

        $this->end_controls_section();
    }

    /** Color/radius/dark-mode controls — also hidden once a preset is chosen. */
    private function registerStyleControls(): void
    {
        $this->start_controls_section('cmc_section_style', [
            'label'     => __('ظاهر', 'campaignchi'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['preset_id' => '0'],
        ]);

        $this->add_control('primary_color', [
            'label'   => __('رنگ اصلی', 'campaignchi'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#6C47FF',
        ]);

        $this->add_control('accent_color', [
            'label'   => __('رنگ تاکیدی', 'campaignchi'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#FF6B35',
        ]);

        $this->add_control('radius', [
            'label'   => __('گردی گوشه‌ها (پیکسل)', 'campaignchi'),
            'type'    => Controls_Manager::NUMBER,
            'min'     => 0,
            'max'     => 40,
            'default' => 16,
        ]);

        $this->add_control('dark_mode', [
            'label'   => __('حالت تیره', 'campaignchi'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => '',
        ]);

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $s = $this->get_settings_for_display();

        $presetId = absint($s['preset_id'] ?? '0');

        if ($presetId > 0) {
            $preset = $this->sliderRepository()->find($presetId);

            if ($preset === null) {
                $this->maybeRenderEditorNotice(__('پریست انتخاب‌شده دیگر وجود ندارد.', 'campaignchi'));
                return;
            }

            $template   = $preset['template'];
            $campaignId = $preset['campaign_id'];
            $resolved   = $this->settingsService()->resolve($preset['settings'], []);
        } else {
            $template   = TemplateRegistry::has((string) ($s['template'] ?? '')) ? $s['template'] : 'flux';
            $campaignId = absint($s['campaign_id'] ?? '0') ?: null;

            // Elementor SWITCHER controls store 'yes'/'' — convert to real PHP
            // booleans BEFORE normalize(), otherwise an explicitly-OFF toggle
            // (empty string) would be (incorrectly) treated as "not specified"
            // by SliderAttributesNormalizer and silently fall back to defaults.
            $raw = [
                'limit'          => $s['limit'] ?? null,
                'order'          => $s['order'] ?? null,
                'title'          => $s['title'] ?? null,
                'autoplay'       => ($s['autoplay'] ?? '') === 'yes',
                'autoplay_speed' => $s['autoplay_speed'] ?? null,
                'loop'           => ($s['loop'] ?? '') === 'yes',
                'arrows'         => ($s['arrows'] ?? '') === 'yes',
                'dots'           => ($s['dots'] ?? '') === 'yes',
                'show_countdown' => ($s['show_countdown'] ?? '') === 'yes',
                'show_stock'     => ($s['show_stock'] ?? '') === 'yes',
                'primary_color'  => $s['primary_color'] ?? null,
                'accent_color'   => $s['accent_color'] ?? null,
                'radius'         => $s['radius'] ?? null,
                'dark_mode'      => ($s['dark_mode'] ?? '') === 'yes',
                'cta_text'       => $s['cta_text'] ?? null,
                'badge_text'     => $s['badge_text'] ?? null,
                // ⚠️ NEW: pass the campaign-type badge override through, same
                // as every other text field above.
                'type_badge_text' => $s['type_badge_text'] ?? null,
            ];

            $resolved = $this->settingsService()->resolve([], SliderAttributesNormalizer::normalize($raw));
        }

        $resolved['template'] = $template;

        $data = $this->dataService()->resolve($campaignId, (int) $resolved['limit'], (string) $resolved['order']);

        if ($data === null) {
            $this->maybeRenderEditorNotice(__('کمپین فعالی برای نمایش در این اسلایدر یافت نشد.', 'campaignchi'));
            return;
        }

        echo $this->renderer()->render($resolved, $data); // phpcs:ignore -- SliderRenderer output is already escaped.
    }

    /** Only the Elementor editor (logged-in admins building the page) ever sees this — the live frontend stays silent. */
    private function maybeRenderEditorNotice(string $message): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        printf(
            '<div style="margin:16px 0;padding:14px 18px;border:1px solid #f5c2c7;background:#fff3f4;color:#7a1f24;border-radius:10px;font-family:sans-serif;font-size:14px;direction:rtl;">%s</div>',
            esc_html($message)
        );
    }

    // ------------------------------------------------------------------
    // Lazy service resolution — see class docblock for why these are NOT
    // constructor-injected.
    // ------------------------------------------------------------------

    private function settingsService(): SliderSettingsService
    {
        return Application::getInstance()->make(SliderSettingsService::class);
    }

    private function sliderRepository(): SliderRepository
    {
        return Application::getInstance()->make(SliderRepository::class);
    }

    private function dataService(): CampaignSliderDataService
    {
        return Application::getInstance()->make(CampaignSliderDataService::class);
    }

    private function renderer(): SliderRenderer
    {
        return Application::getInstance()->make(SliderRenderer::class);
    }

    private function campaignRepository(): CampaignRepository
    {
        return Application::getInstance()->make(CampaignRepository::class);
    }
}