<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

/**
 * ReportsPage
 * TODO: Implement full page content.
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
class ReportsPage extends AbstractPage
{
    public function title(): string
    {
        return __('گزارش‌ها', 'campaignchi');
    }

    public function render(): void
    {
        ?>
        <div class="cmc-empty">
            <div class="cmc-empty__icon"><i class="ti ti-tools"></i></div>
            <div class="cmc-empty__title"><?php esc_html_e('گزارش‌ها', 'campaignchi'); ?></div>
            <div class="cmc-empty__desc"><?php esc_html_e('این بخش در حال توسعه است.', 'campaignchi'); ?></div>
        </div>
        <?php
    }
}
