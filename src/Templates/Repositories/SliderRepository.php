<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Templates\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Slider Repository
 *
 * Persists saved "campaign slider" presets in the wp_cmc_sliders table.
 * A preset bundles: which of the 5 templates to use, which campaign to
 * pull products from (NULL = auto-pick the highest-priority live
 * campaign), and a JSON blob of cosmetic/behavioral overrides (colors,
 * autoplay, toggles, ...).
 *
 * Both the [campaignchi_slider id="X"] shortcode and the Elementor
 * widget's "saved slider" picker read from this table, so editing a
 * preset here updates every place it is used — without touching a single
 * shortcode tag or Elementor widget instance.
 *
 * @package Msi\Campaignchi\Templates\Repositories
 */
final class SliderRepository
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cmc_sliders';
    }

    /**
     * @return array<int, array{id:int,title:string,template:string,campaign_id:?int,settings:array,created_at:string,updated_at:string}>
     */
    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results("SELECT * FROM {$this->table()} ORDER BY id DESC", ARRAY_A) ?: [];

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return array{id:int,title:string,template:string,campaign_id:?int,settings:array,created_at:string,updated_at:string}|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Insert a new slider preset.
     *
     * @param array<string,mixed> $settings Already-sanitized settings overrides (see SliderSettingsService::sanitize()).
     * @return int New preset id.
     */
    public function create(string $title, string $template, ?int $campaignId, array $settings): int
    {
        global $wpdb;

        $now = current_time('mysql', true); // GMT — consistent with CampaignRepository's timestamp convention.

        $wpdb->insert($this->table(), [
            'title'       => $title,
            'template'    => $template,
            'campaign_id' => $campaignId,
            'settings'    => wp_json_encode($settings),
            'created_at'  => $now,
            'updated_at'  => $now,
        ], ['%s', '%s', '%d', '%s', '%s', '%s']);

        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing slider preset.
     *
     * @param array<string,mixed> $settings Already-sanitized settings overrides.
     */
    public function update(int $id, string $title, string $template, ?int $campaignId, array $settings): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            [
                'title'       => $title,
                'template'    => $template,
                'campaign_id' => $campaignId,
                'settings'    => wp_json_encode($settings),
                'updated_at'  => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete($this->table(), ['id' => $id], ['%d']);
    }

    /**
     * @param array<string,mixed> $row Raw ARRAY_A row from $wpdb.
     * @return array{id:int,title:string,template:string,campaign_id:?int,settings:array,created_at:string,updated_at:string}
     */
    private function hydrate(array $row): array
    {
        $settings = json_decode((string) ($row['settings'] ?? ''), true);

        return [
            'id'          => (int) $row['id'],
            'title'       => (string) $row['title'],
            'template'    => (string) $row['template'],
            'campaign_id' => $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null,
            'settings'    => is_array($settings) ? $settings : [],
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }
}
