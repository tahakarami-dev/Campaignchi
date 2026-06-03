<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;

/**
 * Campaigns Page
 *
 * Renders the campaign list OR the create/edit form,
 * based on ?action= query param.
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
class CampaignsPage extends AbstractPage
{
    public function title(): string
    {
        $action = sanitize_key($_GET['action'] ?? 'list');

        return match ($action) {
            'new'  => __('کمپین جدید', 'campaignchi'),
            'edit' => __('ویرایش کمپین', 'campaignchi'),
            default => __('کمپین‌ها', 'campaignchi'),
        };
    }

    public function render(): void
    {
        $action = sanitize_key($_GET['action'] ?? 'list');

        match ($action) {
            'new', 'edit' => $this->renderForm($action),
            default       => $this->renderList(),
        };
    }

    // -------------------------------------------------------
    // LIST VIEW
    // -------------------------------------------------------

    private function renderList(): void
    {
        $repo    = new CampaignRepository();
        $page    = max(1, absint($_GET['paged'] ?? 1));
        $status  = sanitize_key($_GET['filter_status'] ?? '');
        $search  = sanitize_text_field($_GET['s'] ?? '');
        $perPage = 15;

        $result = $repo->paginate([
            'page'     => $page,
            'per_page' => $perPage,
            'status'   => $status,
            'search'   => $search,
        ]);

        $campaigns  = $result['items'];
        $total      = $result['total'];
        $totalPages = (int) ceil($total / $perPage);

        $newUrl = \Msi\Campaignchi\Admin\AdminRouter::url('campaigns', ['action' => 'new']);
?>

        <!-- ---- Page header ---- -->
        <div class="cmc-row cmc-row--between cmc-mb-5">
            <div>
                <h2 style="font-size:var(--cmc-font-size-xl);font-weight:700;color:var(--cmc-text-heading);margin:0">
                    <?php esc_html_e('کمپین‌ها', 'campaignchi'); ?>
                </h2>
                <p style="color:var(--cmc-text-muted);font-size:var(--cmc-font-size-sm);margin:4px 0 0">
                    <?php printf(
                        esc_html__('%d کمپین در سیستم', 'campaignchi'),
                        $total
                    ); ?>
                </p>
            </div>
            <a href="<?php echo esc_url($newUrl); ?>" class="cmc-btn cmc-btn--primary">
                <i class="ti ti-plus"></i>
                <?php esc_html_e('کمپین جدید', 'campaignchi'); ?>
            </a>
        </div>

        <!-- ---- Filter bar ---- -->
        <div class="cmc-card cmc-mb-4" style="padding:var(--cmc-space-4)">
            <form method="GET" class="cmc-row cmc-row--sm" style="flex-wrap:wrap;gap:var(--cmc-space-3)">
                <input type="hidden" name="page" value="campaignchi">
                <input type="hidden" name="cmc_page" value="campaigns">

                <div class="cmc-input-wrap" style="flex:1;min-width:200px">
                    <i class="ti ti-search cmc-input-wrap__icon"></i>
                    <input type="text" name="s" class="cmc-input"
                        value="<?php echo esc_attr($search); ?>"
                        placeholder="<?php esc_attr_e('جستجو در کمپین‌ها...', 'campaignchi'); ?>">
                </div>

                <select name="filter_status" class="cmc-select" style="width:160px">
                    <option value=""><?php esc_html_e('همه وضعیت‌ها', 'campaignchi'); ?></option>
                    <option value="active" <?php selected($status, 'active'); ?>><?php esc_html_e('فعال', 'campaignchi'); ?></option>
                    <option value="draft" <?php selected($status, 'draft'); ?>><?php esc_html_e('پیش‌نویس', 'campaignchi'); ?></option>
                    <option value="scheduled" <?php selected($status, 'scheduled'); ?>><?php esc_html_e('زمان‌بندی شده', 'campaignchi'); ?></option>
                    <option value="ended" <?php selected($status, 'ended'); ?>><?php esc_html_e('پایان یافته', 'campaignchi'); ?></option>
                </select>

                <button type="submit" class="cmc-btn cmc-btn--secondary">
                    <?php esc_html_e('فیلتر', 'campaignchi'); ?>
                </button>
            </form>
        </div>

        <!-- ---- Table ---- -->
        <div class="cmc-card cmc-card--flush">
            <?php if (empty($campaigns)): ?>
                <div class="cmc-empty">
                    <div class="cmc-empty__icon"><i class="ti ti-bolt-off"></i></div>
                    <div class="cmc-empty__title"><?php esc_html_e('هنوز کمپینی ندارید', 'campaignchi'); ?></div>
                    <div class="cmc-empty__desc">
                        <?php esc_html_e('اولین کمپین تخفیف یا پیشنهاد شگفت‌انگیز خود را بسازید.', 'campaignchi'); ?>
                    </div>
                    <a href="<?php echo esc_url($newUrl); ?>" class="cmc-btn cmc-btn--primary">
                        <i class="ti ti-plus"></i>
                        <?php esc_html_e('کمپین اول را بساز', 'campaignchi'); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="cmc-table-wrap">
                    <table class="cmc-table" id="cmc-campaigns-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('عنوان', 'campaignchi'); ?></th>
                                <th><?php esc_html_e('نوع', 'campaignchi'); ?></th>
                                <th><?php esc_html_e('تخفیف', 'campaignchi'); ?></th>
                                <th><?php esc_html_e('وضعیت', 'campaignchi'); ?></th>
                                <th><?php esc_html_e('شروع', 'campaignchi'); ?></th>
                                <th><?php esc_html_e('پایان', 'campaignchi'); ?></th>
                                <th class="hide-mobile"><?php esc_html_e('ایجاد', 'campaignchi'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $campaign): ?>
                                <tr data-id="<?php echo $campaign->id; ?>">
                                    <td class="cmc-table__cell--bold">
                                        <?php echo esc_html($campaign->title); ?>
                                    </td>
                                    <td>
                                        <span class="cmc-badge cmc-badge--primary">
                                            <?php echo esc_html($campaign->typeLabel()); ?>
                                        </span>
                                    </td>
                                    <td class="cmc-table__cell--price">
                                        <?php echo esc_html($campaign->discountLabel()); ?>
                                    </td>
                                    <td>
                                        <span class="cmc-badge <?php echo esc_attr($campaign->statusBadgeClass()); ?>">
                                            <span class="cmc-badge__dot"></span>
                                            <?php echo esc_html($campaign->statusLabel()); ?>
                                        </span>
                                    </td>
                                    <td class="cmc-table__cell--muted">
                                        <?php echo $campaign->startsAt
                                            ? esc_html(wp_date('Y/m/d', strtotime($campaign->startsAt)))
                                            : '—'; ?>
                                    </td>
                                    <td class="cmc-table__cell--muted">
                                        <?php echo $campaign->endsAt
                                            ? esc_html(wp_date('Y/m/d', strtotime($campaign->endsAt)))
                                            : '—'; ?>
                                    </td>
                                    <td class="cmc-table__cell--muted hide-mobile">
                                        <?php echo esc_html(wp_date('Y/m/d', strtotime($campaign->createdAt))); ?>
                                    </td>
                                    <td>
                                        <div class="cmc-table__row-actions">
                                            <a href="<?php echo esc_url(\Msi\Campaignchi\Admin\AdminRouter::url('campaigns', ['action' => 'edit', 'id' => $campaign->id])); ?>"
                                                class="cmc-btn cmc-btn--ghost cmc-btn--icon cmc-btn--sm"
                                                title="<?php esc_attr_e('ویرایش', 'campaignchi'); ?>">
                                                <i class="ti ti-edit"></i>
                                            </a>
                                            <button class="cmc-btn cmc-btn--ghost cmc-btn--icon cmc-btn--sm cmc-delete-campaign"
                                                data-id="<?php echo $campaign->id; ?>"
                                                data-title="<?php echo esc_attr($campaign->title); ?>"
                                                title="<?php esc_attr_e('حذف', 'campaignchi'); ?>">
                                                <i class="ti ti-trash" style="color:var(--cmc-danger)"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="cmc-row" style="padding:var(--cmc-space-4) var(--cmc-space-5);justify-content:space-between;border-top:1px solid var(--cmc-border-light)">
                        <span style="font-size:var(--cmc-font-size-sm);color:var(--cmc-text-muted)">
                            <?php printf(
                                esc_html__('صفحه %d از %d', 'campaignchi'),
                                $page,
                                $totalPages
                            ); ?>
                        </span>
                        <div class="cmc-row cmc-row--sm">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>"
                                    class="cmc-btn cmc-btn--secondary cmc-btn--sm">
                                    <i class="ti ti-arrow-right"></i>
                                    <?php esc_html_e('قبلی', 'campaignchi'); ?>
                                </a>
                            <?php endif; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>"
                                    class="cmc-btn cmc-btn--secondary cmc-btn--sm">
                                    <?php esc_html_e('بعدی', 'campaignchi'); ?>
                                    <i class="ti ti-arrow-left"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>

    <?php
    }

    // -------------------------------------------------------
    // FORM VIEW (create / edit)
    // -------------------------------------------------------

    private function renderForm(string $action): void
    {
        $editId   = absint($_GET['id'] ?? 0);
        $isEdit   = ($action === 'edit' && $editId > 0);
        $backUrl  = \Msi\Campaignchi\Admin\AdminRouter::url('campaigns');

    ?>

        <!-- Back link -->
        <div class="cmc-mb-4">
            <a href="<?php echo esc_url($backUrl); ?>"
                class="cmc-btn cmc-btn--ghost cmc-btn--sm">
                <i class="ti ti-arrow-right"></i>
                <?php esc_html_e('بازگشت به لیست', 'campaignchi'); ?>
            </a>
        </div>

        <div class="cmc-grid" style="grid-template-columns:1fr 340px;gap:var(--cmc-space-4)">

            <!-- ========= LEFT: Main form ========= -->
            <div class="cmc-stack cmc-stack--md">

                <!-- Basic info card -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div class="cmc-card__title"><?php esc_html_e('اطلاعات پایه', 'campaignchi'); ?></div>
                    </div>

                    <div class="cmc-stack cmc-stack--md">

                        <div class="cmc-form-group">
                            <label class="cmc-label cmc-label--required">
                                <?php esc_html_e('عنوان کمپین', 'campaignchi'); ?>
                            </label>
                            <input type="text" id="cmc-field-title" class="cmc-input"
                                placeholder="<?php esc_attr_e('مثلاً: فلش سیل یلدا ۱۴۰۳', 'campaignchi'); ?>"
                                maxlength="255">
                        </div>

                        <div class="cmc-form-group">
                            <label class="cmc-label"><?php esc_html_e('توضیحات', 'campaignchi'); ?></label>
                            <textarea id="cmc-field-desc" class="cmc-textarea" rows="3"
                                placeholder="<?php esc_attr_e('توضیح کوتاه درباره این کمپین...', 'campaignchi'); ?>"></textarea>
                        </div>

                        <!-- Type toggle -->
                        <div class="cmc-form-group">
                            <label class="cmc-label"><?php esc_html_e('نوع کمپین', 'campaignchi'); ?></label>
                            <div class="cmc-type-toggle" id="cmc-type-toggle">
                                <button type="button" class="cmc-type-btn is-active" data-value="flash_sale">
                                    <i class="ti ti-clock-bolt"></i>
                                    <?php esc_html_e('فلش سیل', 'campaignchi'); ?>
                                    <span><?php esc_html_e('محدود به زمان', 'campaignchi'); ?></span>
                                </button>
                                <button type="button" class="cmc-type-btn" data-value="amazing_offer">
                                    <i class="ti ti-star"></i>
                                    <?php esc_html_e('پیشنهاد شگفت‌انگیز', 'campaignchi'); ?>
                                    <span><?php esc_html_e('بدون محدودیت زمانی', 'campaignchi'); ?></span>
                                </button>
                            </div>
                            <input type="hidden" id="cmc-field-type" value="flash_sale">
                        </div>

                    </div>
                </div>

                <!-- Discount card -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div class="cmc-card__title"><?php esc_html_e('تخفیف', 'campaignchi'); ?></div>
                    </div>
                    <div class="cmc-row cmc-row--md" style="align-items:flex-start">

                        <div class="cmc-form-group" style="flex:1">
                            <label class="cmc-label cmc-label--required">
                                <?php esc_html_e('مقدار تخفیف', 'campaignchi'); ?>
                            </label>
                            <input type="number" id="cmc-field-discount" class="cmc-input"
                                placeholder="30" min="0" step="0.01">
                        </div>

                        <div class="cmc-form-group" style="width:160px">
                            <label class="cmc-label"><?php esc_html_e('نوع تخفیف', 'campaignchi'); ?></label>
                            <div class="cmc-discount-type-toggle" id="cmc-discount-type-toggle" style="gap: 8px;">
                                <button type="button" class="cmc-dt-btn is-active" data-value="percent" style="border-radius: 14px;">٪ درصد</button>
                                <button type="button" class="cmc-dt-btn" data-value="fixed" style="border-radius: 14px;" cmc-discount-type-toggle>تومان ثابت</button>
                            </div>
                            <input type="hidden" id="cmc-field-discount-type" value="percent">
                        </div>

                    </div>
                </div>

                <!-- Schedule card -->
                <div class="cmc-card">
                    <div class="cmc-card__header">
                        <div class="cmc-card__title"><?php esc_html_e('زمان‌بندی', 'campaignchi'); ?></div>
                        <span style="font-size:var(--cmc-font-size-xs);color:var(--cmc-text-muted)">
                            <?php esc_html_e('اختیاری', 'campaignchi'); ?>
                        </span>
                    </div>
                    <div class="cmc-grid cmc-grid--2" style="gap:var(--cmc-space-4)">
                        <div class="cmc-form-group">
                            <label class="cmc-label"><?php esc_html_e('تاریخ شروع', 'campaignchi'); ?></label>
                            <div class="cmc-input-wrap">
                                <i class="ti ti-calendar cmc-input-wrap__icon"></i>
                                <input type="text"
                                    id="cmc-field-starts-at-display"
                                    class="cmc-input"
                                    data-cmc-datepicker="cmc-field-starts-at"
                                    placeholder="<?php esc_attr_e('انتخاب تاریخ و ساعت...', 'campaignchi'); ?>"
                                    readonly>
                            </div>
                            <input type="hidden" id="cmc-field-starts-at">
                        </div>
                        <div class="cmc-form-group">
                            <label class="cmc-label"><?php esc_html_e('تاریخ پایان', 'campaignchi'); ?></label>
                            <div class="cmc-input-wrap">
                                <i class="ti ti-calendar cmc-input-wrap__icon"></i>
                                <input type="text"
                                    id="cmc-field-ends-at-display"
                                    class="cmc-input"
                                    data-cmc-datepicker="cmc-field-ends-at"
                                    placeholder="<?php esc_attr_e('انتخاب تاریخ و ساعت...', 'campaignchi'); ?>"
                                    readonly>
                            </div>
                            <input type="hidden" id="cmc-field-ends-at">
                        </div>
                    </div>
                </div>

                <!-- ===== PRODUCT PICKER CARD ===== -->
                <div class="cmc-card" style="margin-bottom: 30px;">
                    <div class="cmc-card__header">
                        <div class="cmc-card__title"><?php esc_html_e('محصولات کمپین', 'campaignchi'); ?></div>
                    </div>

                    <!-- Selection mode tabs -->
                    <div class="cmc-tabs cmc-mb-4" id="cmc-picker-tabs">
                        <div class="cmc-tab is-active" data-mode="manual">
                            <i class="ti ti-search"></i> <?php esc_html_e('انتخاب دستی', 'campaignchi'); ?>
                        </div>
                        <div class="cmc-tab" data-mode="category">
                            <i class="ti ti-category"></i> <?php esc_html_e('دسته‌بندی', 'campaignchi'); ?>
                        </div>
                        <div class="cmc-tab" data-mode="tag">
                            <i class="ti ti-tag"></i> <?php esc_html_e('برچسب', 'campaignchi'); ?>
                        </div>
                        <div class="cmc-tab" data-mode="attribute">
                            <i class="ti ti-adjustments"></i> <?php esc_html_e('ویژگی', 'campaignchi'); ?>
                        </div>
                        <div class="cmc-tab" data-mode="brand">
                            <i class="ti ti-building-store"></i> <?php esc_html_e('برند', 'campaignchi'); ?>
                        </div>
                        <div class="cmc-tab" data-mode="all">
                            <i class="ti ti-select-all"></i> <?php esc_html_e('همه محصولات', 'campaignchi'); ?>
                        </div>
                    </div>
                    <input type="hidden" id="cmc-field-selection-mode" value="manual">

                    <!-- PANEL: Manual product search -->
                    <div id="cmc-panel-manual" class="cmc-picker-panel">
                        <div class="cmc-input-wrap cmc-mb-3">
                            <i class="ti ti-search cmc-input-wrap__icon"></i>
                            <input type="text" id="cmc-product-search"
                                class="cmc-input"
                                placeholder="<?php esc_attr_e('نام محصول یا SKU را تایپ کنید...', 'campaignchi'); ?>"
                                autocomplete="off">
                        </div>
                        <div id="cmc-search-results" class="cmc-product-results" style="display:none"></div>
                        <div id="cmc-search-loading" class="cmc-picker-loading" style="display:none">
                            <i class="ti ti-loader-2 cmc-spin"></i>
                            <?php esc_html_e('در حال جستجو...', 'campaignchi'); ?>
                        </div>
                    </div>

                    <!-- PANEL: Category selection -->
                    <div id="cmc-panel-category" class="cmc-picker-panel" style="display:none">
                        <div id="cmc-category-list" class="cmc-term-list">
                            <div class="cmc-picker-loading">
                                <i class="ti ti-loader-2 cmc-spin"></i>
                                <?php esc_html_e('در حال بارگذاری...', 'campaignchi'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL: Tag selection -->
                    <div id="cmc-panel-tag" class="cmc-picker-panel" style="display:none">
                        <div id="cmc-tag-list" class="cmc-term-list">
                            <div class="cmc-picker-loading">
                                <i class="ti ti-loader-2 cmc-spin"></i>
                                <?php esc_html_e('در حال بارگذاری...', 'campaignchi'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL: Attribute selection -->
                    <div id="cmc-panel-attribute" class="cmc-picker-panel" style="display:none">
                        <div id="cmc-attribute-list" class="cmc-term-list">
                            <div class="cmc-picker-loading">
                                <i class="ti ti-loader-2 cmc-spin"></i>
                                <?php esc_html_e('در حال بارگذاری...', 'campaignchi'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL: Brand selection -->
                    <div id="cmc-panel-brand" class="cmc-picker-panel" style="display:none">
                        <div id="cmc-brand-list" class="cmc-term-list">
                            <div class="cmc-picker-loading">
                                <i class="ti ti-loader-2 cmc-spin"></i>
                                <?php esc_html_e('در حال بارگذاری...', 'campaignchi'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL: All products -->
                    <div id="cmc-panel-all" class="cmc-picker-PanelLayout" style="display:none">
                        <div class="cmc-alert cmc-alert--warning">
                            <i class="ti ti-alert-triangle cmc-alert__icon"></i>
                            <div class="cmc-alert__body">
                                <div class="cmc-alert__title"><?php esc_html_e('اعمال روی همه محصولات', 'campaignchi'); ?></div>
                                <?php esc_html_e('تخفیف این کمپین روی تمام محصولات منتشرشده فروشگاه اعمال خواهد شد.', 'campaignchi'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Selected products preview (always visible) -->
                    <div id="cmc-selected-wrap" style="margin-top:var(--cmc-space-4);display:none">
                        <div class="cmc-row cmc-row--between cmc-mb-3">
                            <span style="font-size:var(--cmc-font-size-sm);font-weight:600;color:var(--cmc-text-heading)">
                                <?php esc_html_e('محصولات انتخابی', 'campaignchi'); ?>
                            </span>
                            <span id="cmc-selected-count" class="cmc-badge cmc-badge--primary">0</span>
                        </div>
                        <div id="cmc-selected-list" class="cmc-selected-products"></div>
                    </div>

                </div>
                <!-- /PRODUCT PICKER -->

            </div>
            <!-- /LEFT -->

            <!-- ========= RIGHT: Sidebar ========= -->
            <div class="cmc-stack cmc-stack--md campaign-summary">

                <!-- Publish card -->
                <div class="cmc-card">
                    <div class="cmc-card__title cmc-mb-4">
                        <?php esc_html_e('انتشار', 'campaignchi'); ?>
                    </div>

                    <div class="cmc-form-group cmc-mb-4">
                        <label class="cmc-label"><?php esc_html_e('وضعیت', 'campaignchi'); ?></label>
                        <select id="cmc-field-status" class="cmc-select">
                            <option value="draft"><?php esc_html_e('پیش‌نویس', 'campaignchi'); ?></option>
                            <option value="active"><?php esc_html_e('فعال — منتشر شود', 'campaignchi'); ?></option>
                            <option value="scheduled"><?php esc_html_e('زمان‌بندی شده', 'campaignchi'); ?></option>
                        </select>
                    </div>

                    <div id="cmc-form-error" class="cmc-alert cmc-alert--danger cmc-mb-3" style="display:none">
                        <i class="ti ti-alert-circle cmc-alert__icon"></i>
                        <div class="cmc-alert__body" id="cmc-form-error-text"></div>
                    </div>

                    <button type="button" id="cmc-btn-save"
                        class="cmc-btn cmc-btn--primary"
                        style="width:100%"
                        data-edit-id="<?php echo $isEdit ? $editId : 0; ?>">
                        <i class="ti ti-device-floppy"></i>
                        <?php echo $isEdit
                            ? esc_html__('ذخیره تغییرات', 'campaignchi')
                            : esc_html__('ایجاد کمپین', 'campaignchi'); ?>
                    </button>

                    <?php if ($isEdit): ?>
                        <button type="button" id="cmc-btn-delete"
                            class="cmc-btn cmc-btn--danger"
                            style="width:100%;margin-top:var(--cmc-space-2)"
                            data-id="<?php echo $editId; ?>">
                            <i class="ti ti-trash"></i>
                            <?php esc_html_e('حذف کمپین', 'campaignchi'); ?>
                        </button>
                    <?php endif; ?>

                </div>

                <!-- Summary card -->
                <div class="cmc-card" id="cmc-summary-card">
                    <div class="cmc-card__title cmc-mb-3">
                        <?php esc_html_e('خلاصه', 'campaignchi'); ?>
                    </div>
                    <div class="cmc-stack cmc-stack--sm" style="font-size:var(--cmc-font-size-sm)">
                        <div class="cmc-row cmc-row--between">
                            <span style="color:var(--cmc-text-muted)"><?php esc_html_e('نوع:', 'campaignchi'); ?></span>
                            <span id="cmc-sum-type" style="font-weight:600">—</span>
                        </div>
                        <div class="cmc-row cmc-row--between">
                            <span style="color:var(--cmc-text-muted)"><?php esc_html_e('تخفیف:', 'campaignchi'); ?></span>
                            <span id="cmc-sum-discount" style="font-weight:600;color:var(--cmc-accent)">—</span>
                        </div>
                        <div class="cmc-row cmc-row--between">
                            <span style="color:var(--cmc-text-muted)"><?php esc_html_e('محصولات:', 'campaignchi'); ?></span>
                            <span id="cmc-sum-products" style="font-weight:600">—</span>
                        </div>
                    </div>
                </div>

            </div>
            <!-- /RIGHT -->

        </div>

        <!-- Campaign form CSS + JS -->
        <style>
            /* Type toggle buttons */
            .cmc-type-toggle {
                display: flex;
                gap: 8px;
            }

            .cmc-type-btn {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 4px;
                padding: 12px 8px;
                border: 1.5px solid var(--cmc-border);
                border-radius: var(--cmc-radius-md);
                background: var(--cmc-surface);
                cursor: pointer;
                font-family: var(--cmc-font);
                font-size: 13px;
                font-weight: 600;
                color: var(--cmc-text-body);
                transition: all var(--cmc-transition);
            }

            .cmc-type-btn i {
                font-size: 22px;
                color: var(--cmc-text-muted);
            }

            .cmc-type-btn span {
                font-size: 10px;
                color: var(--cmc-text-muted);
                font-weight: 400;
            }

            .cmc-type-btn.is-active {
                border-color: var(--cmc-primary-500);
                background: var(--cmc-primary-50);
                color: var(--cmc-primary-500);
            }

            .cmc-type-btn.is-active i {
                color: var(--cmc-primary-500);
            }

            /* Discount type mini toggle */
            .cmc-discount-type-toggle {
                display: flex;
            }

            .cmc-dt-btn {
                flex: 1;
                padding: 9px 8px;
                border: 1px solid var(--cmc-border);
                background: var(--cmc-surface);
                cursor: pointer;
                font-family: var(--cmc-font);
                font-size: 12px;
                font-weight: 600;
                color: var(--cmc-text-muted);
                transition: all var(--cmc-transition);
            }

            .cmc-dt-btn:first-child {
                border-radius: var(--cmc-radius-md) 0 0 var(--cmc-radius-md);
            }

            .cmc-dt-btn:last-child {
                border-radius: 0 var(--cmc-radius-md) var(--cmc-radius-md) 0;
                border-right: none;
            }

            .cmc-dt-btn.is-active {
                background: var(--cmc-primary-500);
                color: #fff;
                border-color: var(--cmc-primary-500);
            }

            /* Picker tabs — override base cmc-tab to work inline */
            #cmc-picker-tabs .cmc-tab {
                font-size: 12px;
                padding: 8px 12px;
                gap: 5px;
            }

            /* Product search results */
            .cmc-product-results {
                max-height: 260px;
                overflow-y: auto;
                border: 1px solid var(--cmc-border);
                border-radius: var(--cmc-radius-md);
            }

            .cmc-product-result-item {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px 12px;
                border-bottom: 1px solid var(--cmc-border-light);
                cursor: pointer;
                transition: background var(--cmc-transition);
            }

            .cmc-product-result-item:last-child {
                border-bottom: none;
            }

            .cmc-product-result-item:hover {
                background: var(--cmc-surface-2);
            }

            .cmc-product-result-item.is-selected {
                background: var(--cmc-primary-50);
            }

            .cmc-product-result-item img {
                width: 38px;
                height: 38px;
                border-radius: var(--cmc-radius-sm);
                object-fit: cover;
                flex-shrink: 0;
            }

            .cmc-product-result-item__name {
                font-size: 13px;
                font-weight: 600;
                color: var(--cmc-text-heading);
            }

            .cmc-product-result-item__meta {
                font-size: 11px;
                color: var(--cmc-text-muted);
                margin-top: 2px;
            }

            .cmc-product-result-item__price {
                font-size: 12px;
                font-weight: 700;
                color: var(--cmc-primary-500);
                margin-right: auto;
                white-space: nowrap;
            }

            .cmc-product-result-item__check {
                width: 20px;
                height: 20px;
                border-radius: 50%;
                border: 2px solid var(--cmc-border);
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .cmc-product-result-item.is-selected .cmc-product-result-item__check {
                background: var(--cmc-primary-500);
                border-color: var(--cmc-primary-500);
                color: #fff;
            }

            /* Term list (categories/tags/attributes) */
            .cmc-term-list {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                padding: 4px 0;
            }

            .cmc-term-chip {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 5px 10px;
                border: 1.5px solid var(--cmc-border);
                border-radius: var(--cmc-radius-pill);
                font-size: 12px;
                font-weight: 500;
                color: var(--cmc-text-body);
                cursor: pointer;
                background: var(--cmc-surface);
                transition: all var(--cmc-transition);
            }

            .cmc-term-chip:hover {
                border-color: var(--cmc-primary-500);
                color: var(--cmc-primary-500);
            }

            .cmc-term-chip.is-selected {
                background: var(--cmc-primary-500);
                border-color: var(--cmc-primary-500);
                color: #fff;
            }

            .cmc-term-chip__count {
                opacity: 0.6;
                font-size: 10px;
            }

            .cmc-attr-group {
                width: 100%;
            }

            .cmc-attr-group__label {
                font-size: 11px;
                font-weight: 700;
                color: var(--cmc-text-muted);
                text-transform: uppercase;
                letter-spacing: 0.06em;
                margin-bottom: 6px;
            }

            /* Selected products list */
            .cmc-selected-products {
                display: flex;
                flex-direction: column;
                gap: 6px;
                max-height: 220px;
                overflow-y: auto;
            }

            .cmc-selected-product {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px;
                border: 1px solid var(--cmc-border);
                border-radius: var(--cmc-radius-sm);
                background: var(--cmc-surface);
            }

            .cmc-selected-product img {
                width: 32px;
                height: 32px;
                border-radius: 6px;
                object-fit: cover;
                flex-shrink: 0;
            }

            .cmc-selected-product__name {
                font-size: 12px;
                font-weight: 600;
                flex: 1;
                color: var(--cmc-text-heading);
            }

            .cmc-selected-product__remove {
                width: 22px;
                height: 22px;
                border-radius: 50%;
                border: none;
                background: var(--cmc-surface-2);
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--cmc-text-muted);
                font-size: 12px;
                flex-shrink: 0;
                transition: all var(--cmc-transition);
            }

            .cmc-selected-product__remove:hover {
                background: var(--cmc-danger-light);
                color: var(--cmc-danger);
            }

            /* Loading spinner */
            .cmc-picker-loading {
                display: flex;
                align-items: center;
                gap: 8px;
                color: var(--cmc-text-muted);
                font-size: 13px;
                padding: 12px 0;
            }

            .cmc-spin {
                animation: cmc-spin 0.8s linear infinite;
                display: inline-block;
            }

            @keyframes cmc-spin {
                to {
                    transform: rotate(360deg);
                }
            }

            /* Load more */
            .cmc-load-more {
                width: 100%;
                padding: 8px;
                text-align: center;
                font-size: 12px;
                color: var(--cmc-primary-500);
                cursor: pointer;
                border: none;
                background: none;
                font-family: var(--cmc-font);
                border-top: 1px solid var(--cmc-border-light);
            }

            .cmc-load-more:hover {
                background: var(--cmc-primary-50);
            }
        </style>

        <script>
            // Pass edit data to campaigns.js
            window.CMC_FORM = {
                isEdit: <?php echo wp_json_encode($isEdit); ?>,
                editId: <?php echo wp_json_encode($isEdit ? $editId : 0); ?>,
                backUrl: <?php echo wp_json_encode($backUrl); ?>,
            };
        </script>

<?php
    }
}
