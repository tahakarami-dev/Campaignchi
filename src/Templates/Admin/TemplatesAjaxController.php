<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Admin;

use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Templates\Repositories\SliderRepository;
use Msi\Campaignchi\Templates\Renderers\SliderRenderer;
use Msi\Campaignchi\Templates\Services\CampaignSliderDataService;
use Msi\Campaignchi\Templates\Services\SliderSettingsService;
use Msi\Campaignchi\Templates\TemplateRegistry;

/**
 * Templates AJAX Controller
 *
 * Backs the admin "Templates" page: toggling which skins are enabled,
 * saving global appearance defaults, populating the campaign picker, the
 * live WYSIWYG preview, and full CRUD for saved slider presets.
 *
 * Every handler follows the same pattern already used by
 * \Msi\Campaignchi\Admin\Controllers\CampaignController: verifyNonce()
 * first, then json($data, $status) to emit the response and halt
 * execution (json() is declared `never`-returning via `exit`).
 *
 * @package Msi\Campaignchi\Templates\Admin
 */
class TemplatesAjaxController
{
    public function __construct(
        private SliderSettingsService $settings,
        private SliderRepository $sliders,
        private CampaignSliderDataService $dataService,
        private SliderRenderer $renderer,
        private CampaignRepository $campaigns
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_cmc_toggle_template_enabled', [$this, 'toggleTemplateEnabled']);
        add_action('wp_ajax_cmc_save_global_slider_settings', [$this, 'saveGlobalSliderSettings']);
        add_action('wp_ajax_cmc_get_campaigns_for_picker', [$this, 'getCampaignsForPicker']);
        add_action('wp_ajax_cmc_preview_slider', [$this, 'previewSlider']);
        add_action('wp_ajax_cmc_save_slider', [$this, 'saveSlider']);
        add_action('wp_ajax_cmc_update_slider', [$this, 'updateSlider']);
        add_action('wp_ajax_cmc_delete_slider', [$this, 'deleteSlider']);
        add_action('wp_ajax_cmc_get_slider', [$this, 'getSlider']);
    }

    public function toggleTemplateEnabled(): void
    {
        $this->verifyNonce();

        $templateId = isset($_POST['template_id']) ? sanitize_key((string) $_POST['template_id']) : '';
        $enabled    = !empty($_POST['enabled']) && $_POST['enabled'] !== '0';

        if (!TemplateRegistry::has($templateId)) {
            $this->json(['message' => __('قالب نامعتبر است.', 'campaignchi')], 400);
        }

        $list = $this->settings->setTemplateEnabled($templateId, $enabled);

        $this->json(['enabled_templates' => $list]);
    }

    public function saveGlobalSliderSettings(): void
    {
        $this->verifyNonce();

        $input = wp_unslash($_POST);
        unset($input['action'], $input['_ajax_nonce'], $input['nonce']);

        $saved = $this->settings->saveGlobalSettings($input);

        $this->json(['settings' => $saved]);
    }

    public function getCampaignsForPicker(): void
    {
        $this->verifyNonce();

        $campaigns = array_map(
            static fn ($campaign) => [
                'id'    => $campaign->id,
                'title' => $campaign->title,
                'label' => $campaign->title . ' — ' . $campaign->statusLabel(),
            ],
            $this->campaigns->getNonDraftCampaigns()
        );

        $this->json(['campaigns' => $campaigns]);
    }

    public function previewSlider(): void
    {
        $this->verifyNonce();

        $input    = wp_unslash($_POST);
        $template = isset($input['template']) ? sanitize_key((string) $input['template']) : 'flux';

        if (!TemplateRegistry::has($template)) {
            $template = 'flux';
        }

        $campaignId = absint($input['campaign_id'] ?? 0) ?: null;

        $resolved = $this->settings->resolve([], \Msi\Campaignchi\Templates\Support\SliderAttributesNormalizer::normalize($input));
        $resolved['template'] = $template;

        $data = $this->dataService->resolve($campaignId, (int) $resolved['limit'], (string) $resolved['order']);

        if ($data === null) {
            $this->json([
                'html' => '<div style="padding:40px;text-align:center;color:#9aa0ac;font-family:sans-serif;">'
                    . esc_html__('کمپین فعالی برای پیش‌نمایش پیدا نشد.', 'campaignchi') . '</div>',
            ]);
        }

        $this->json(['html' => $this->renderer->render($resolved, $data)]);
    }

    public function saveSlider(): void
    {
        $this->verifyNonce();

        $input = wp_unslash($_POST);
        $title = sanitize_text_field((string) ($input['title'] ?? ''));

        if ($title === '') {
            $this->json(['message' => __('عنوان اسلایدر الزامی است.', 'campaignchi')], 400);
        }

        $template = isset($input['template']) ? sanitize_key((string) $input['template']) : 'flux';
        if (!TemplateRegistry::has($template)) {
            $template = 'flux';
        }

        $campaignId = absint($input['campaign_id'] ?? 0) ?: null;
        $settings   = $this->settings->sanitize(\Msi\Campaignchi\Templates\Support\SliderAttributesNormalizer::normalize($input));

        $id = $this->sliders->create($title, $template, $campaignId, $settings);

        $this->json([
            'id'        => $id,
            'shortcode' => sprintf('[%s id="%d"]', \Msi\Campaignchi\Templates\Shortcode\CampaignSliderShortcode::TAG, $id),
        ]);
    }

    public function updateSlider(): void
    {
        $this->verifyNonce();

        $input = wp_unslash($_POST);
        $id    = absint($input['id'] ?? 0);

        if ($id <= 0 || $this->sliders->find($id) === null) {
            $this->json(['message' => __('اسلایدر پیدا نشد.', 'campaignchi')], 404);
        }

        $title = sanitize_text_field((string) ($input['title'] ?? ''));
        if ($title === '') {
            $this->json(['message' => __('عنوان اسلایدر الزامی است.', 'campaignchi')], 400);
        }

        $template = isset($input['template']) ? sanitize_key((string) $input['template']) : 'flux';
        if (!TemplateRegistry::has($template)) {
            $template = 'flux';
        }

        $campaignId = absint($input['campaign_id'] ?? 0) ?: null;
        $settings   = $this->settings->sanitize(\Msi\Campaignchi\Templates\Support\SliderAttributesNormalizer::normalize($input));

        $this->sliders->update($id, $title, $template, $campaignId, $settings);

        $this->json([
            'id'        => $id,
            'shortcode' => sprintf('[%s id="%d"]', \Msi\Campaignchi\Templates\Shortcode\CampaignSliderShortcode::TAG, $id),
        ]);
    }

    public function deleteSlider(): void
    {
        $this->verifyNonce();

        $id = absint($_POST['id'] ?? 0);

        if ($id > 0) {
            $this->sliders->delete($id);
        }

        $this->json(['deleted' => true]);
    }

    public function getSlider(): void
    {
        $this->verifyNonce();

        // CMC.ajax() in panel.js always sends a POST request (via FormData),
        // so the id MUST be read from $_POST here, not $_GET.
        $id     = absint($_POST['id'] ?? 0);
        $preset = $id > 0 ? $this->sliders->find($id) : null;

        if ($preset === null) {
            $this->json(['message' => __('اسلایدر پیدا نشد.', 'campaignchi')], 404);
        }

        $this->json(['slider' => $preset]);
    }

    private function verifyNonce(): void
    {
        // ⚠️ BUG FIX: the frontend CMC.ajax() helper (panel.js) always sends
        // the nonce under the POST key 'nonce'. check_ajax_referer()'s
        // default $query_arg (false) only looks at '_ajax_nonce'/'_wpnonce',
        // so it would NEVER find it here — and with the default $stop=true
        // it would wp_die() on every single request to this controller,
        // breaking the entire Templates admin page. Passing 'nonce' as the
        // second argument (and $stop=false, so we can return our own JSON
        // error) mirrors the exact working pattern already used by
        // \Msi\Campaignchi\Admin\Controllers\CampaignController::verifyNonce().
        if (!check_ajax_referer('cmc_admin', 'nonce', false)) {
            $this->json(['message' => __('درخواست نامعتبر.', 'campaignchi')], 403);
        }

        if (!current_user_can('manage_options')) {
            $this->json(['message' => __('دسترسی غیرمجاز.', 'campaignchi')], 403);
        }
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = 200): never
    {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($data);
        exit;
    }
}