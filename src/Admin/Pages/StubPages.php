<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

/**
 * CampaignsPage — Campaign list and management
 */
final class CampaignsPage extends AdminPage
{
    public function getTitle(): string
    {
        return __('کمپین‌ها', 'campaignchi');
    }

    public function getTopbarActions(): string
    {
        ob_start();
        ?>
        <button class="cmc-btn cmc-btn--primary cmc-btn--sm" id="cmc-btn-create-campaign">
            <i class="ti ti-plus" style="font-size:14px;"></i>
            <?php esc_html_e('کمپین جدید', 'campaignchi'); ?>
        </button>
        <?php
        return ob_get_clean();
    }

    public function render(): void
    {
        ?>
        <div class="cmc-empty">
            <div class="cmc-empty__icon"><i class="ti ti-bolt"></i></div>
            <div class="cmc-empty__title"><?php esc_html_e('هنوز کمپینی ندارید', 'campaignchi'); ?></div>
            <div class="cmc-empty__desc">
                <?php esc_html_e('اولین کمپین تخفیف یا فلش سیل خود را بسازید.', 'campaignchi'); ?>
            </div>
            <button class="cmc-btn cmc-btn--primary">
                <i class="ti ti-plus"></i>
                <?php esc_html_e('ساخت کمپین', 'campaignchi'); ?>
            </button>
        </div>
        <?php
    }
}

/**
 * ProductsPage — Product selection for campaigns
 */
final class ProductsPage extends AdminPage
{
    public function getTitle(): string
    {
        return __('محصولات', 'campaignchi');
    }

    public function render(): void
    {
        ?>
        <div class="cmc-empty">
            <div class="cmc-empty__icon"><i class="ti ti-package"></i></div>
            <div class="cmc-empty__title"><?php esc_html_e('مدیریت محصولات', 'campaignchi'); ?></div>
            <div class="cmc-empty__desc">
                <?php esc_html_e('به‌زودی: انتخاب و مدیریت محصولات برای کمپین‌ها.', 'campaignchi'); ?>
            </div>
        </div>
        <?php
    }
}

/**
 * SchedulePage — Campaign scheduling calendar
 */
final class SchedulePage extends AdminPage
{
    public function getTitle(): string
    {
        return __('زمان‌بندی', 'campaignchi');
    }

    public function render(): void
    {
        ?>
        <div class="cmc-empty">
            <div class="cmc-empty__icon"><i class="ti ti-calendar-time"></i></div>
            <div class="cmc-empty__title"><?php esc_html_e('زمان‌بندی کمپین‌ها', 'campaignchi'); ?></div>
            <div class="cmc-empty__desc">
                <?php esc_html_e('به‌زودی: تقویم و زمان‌بندی کمپین‌های تخفیف.', 'campaignchi'); ?>
            </div>
        </div>
        <?php
    }
}

/**
 * TemplatesPage — Frontend display templates
 */
final class TemplatesPage extends AdminPage
{
    public function getTitle(): string
    {
        return __('قالب‌ها', 'campaignchi');
    }

    public function render(): void
    {
        ?>
        <div class="cmc-empty">
            <div class="cmc-empty__icon"><i class="ti ti-layout-2"></i></div>
            <div class="cmc-empty__title"><?php esc_html_e('قالب‌های نمایش', 'campaignchi'); ?></div>
            <div class="cmc-empty__desc">
                <?php esc_html_e('به‌زودی: انتخاب و سفارشی‌سازی قالب‌های فرانت‌اند.', 'campaignchi'); ?>
            </div>
        </div>
        <?php
    }
}

/**
 * ReportsPage — Analytics and reporting
 */
final class ReportsPage extends AdminPage
{
    public function getTitle(): string
    {
        return __('گزارش‌ها', 'campaignchi');
    }

    public function render(): void
    {
        ?>
        <div class="cmc-empty">
            <div class="cmc-empty__icon"><i class="ti ti-chart-bar"></i></div>
            <div class="cmc-empty__title"><?php esc_html_e('گزارش‌های آنالیتیکس', 'campaignchi'); ?></div>
            <div class="cmc-empty__desc">
                <?php esc_html_e('به‌زودی: آمار فروش، نرخ تبدیل و عملکرد کمپین‌ها.', 'campaignchi'); ?>
            </div>
        </div>
        <?php
    }
}

/**
 * SettingsPage — Plugin settings panel
 */
final class SettingsPage extends AdminPage
{
    public function getTitle(): string
    {
        return __('تنظیمات', 'campaignchi');
    }

    public function getTopbarActions(): string
    {
        ob_start();
        ?>
        <button class="cmc-btn cmc-btn--primary cmc-btn--sm" id="cmc-btn-save-settings">
            <i class="ti ti-device-floppy" style="font-size:14px;"></i>
            <?php esc_html_e('ذخیره تنظیمات', 'campaignchi'); ?>
        </button>
        <?php
        return ob_get_clean();
    }

    public function render(): void
    {
        ?>
        <div class="cmc-card" style="max-width:640px;">
            <div class="cmc-card__header">
                <div class="cmc-card__title"><?php esc_html_e('تنظیمات عمومی', 'campaignchi'); ?></div>
            </div>

            <div class="cmc-stack cmc-stack--md">

                <div class="cmc-form-group">
                    <label class="cmc-label"><?php esc_html_e('نام افزونه در فرانت‌اند', 'campaignchi'); ?></label>
                    <input type="text" class="cmc-input" value="کمپین‌چی" placeholder="نام نمایشی">
                </div>

                <div class="cmc-form-group">
                    <label class="cmc-label"><?php esc_html_e('ارز پیش‌فرض', 'campaignchi'); ?></label>
                    <select class="cmc-select">
                        <option>تومان</option>
                        <option>ریال</option>
                        <option>دلار</option>
                    </select>
                </div>

                <hr class="cmc-divider">

                <div class="cmc-row cmc-row--between">
                    <div>
                        <div class="cmc-label"><?php esc_html_e('نمایش شمارش معکوس', 'campaignchi'); ?></div>
                        <div class="cmc-form-hint"><?php esc_html_e('در کارت محصول نمایش داده می‌شود', 'campaignchi'); ?></div>
                    </div>
                    <label class="cmc-toggle">
                        <input type="checkbox" class="cmc-toggle__input" checked>
                        <div class="cmc-toggle__track"><div class="cmc-toggle__thumb"></div></div>
                    </label>
                </div>

                <div class="cmc-row cmc-row--between">
                    <div>
                        <div class="cmc-label"><?php esc_html_e('نمایش badge تخفیف', 'campaignchi'); ?></div>
                        <div class="cmc-form-hint"><?php esc_html_e('درصد تخفیف روی تصویر محصول', 'campaignchi'); ?></div>
                    </div>
                    <label class="cmc-toggle">
                        <input type="checkbox" class="cmc-toggle__input" checked>
                        <div class="cmc-toggle__track"><div class="cmc-toggle__thumb"></div></div>
                    </label>
                </div>

            </div>
        </div>
        <?php
    }
}
