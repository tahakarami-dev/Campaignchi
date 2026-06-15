<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

use Msi\Campaignchi\Analytics\Services\AnalyticsService;
use Msi\Campaignchi\Core\Application;
use Msi\Campaignchi\Helpers\JalaliHelper;

/**
 * Dashboard Page
 *
 * تمام اعداد این صفحه از AnalyticsService خوانده می‌شوند و
 * بر اساس محصولاتی که الان زیر یک کمپین زنده هستند محاسبه می‌شوند —
 * نه کل فروش/بازدید/سفارش‌های ووکامرس.
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
class DashboardPage extends AbstractPage
{
    public function title(): string
    {
        return __('داشبورد', 'campaignchi');
    }

    public function render(): void
    {
        $analytics = $this->analytics();

        $stats           = $analytics->getStatCards();
        $chart           = $analytics->getWeeklyChart();
        $activeCampaigns = $analytics->getActiveCampaignsWidget(2);
        $topProducts     = $analytics->getTopProducts(3);
        $activity        = $analytics->getRecentActivity(4);

        ?>

        <!-- ============================================================
             STAT CARDS ROW
        ============================================================ -->
        <div class="cmc-grid cmc-grid--4 cmc-mb-5">

            <?php $this->renderStatCard(
                __('کمپین فعال', 'campaignchi'),
                $stats['active_campaigns'],
                'ti-bolt',
                'purple'
            ); ?>

            <?php $this->renderStatCard(
                __('فروش امروز (محصولات کمپین)', 'campaignchi'),
                $stats['sales_today'],
                'ti-chart-line',
                'orange'
            ); ?>

            <?php $this->renderStatCard(
                __('نرخ تبدیل کمپین‌ها', 'campaignchi'),
                $stats['conversion_rate'],
                'ti-click',
                'green'
            ); ?>

            <?php $this->renderStatCard(
                __('بازدید امروز (محصولات کمپین)', 'campaignchi'),
                $stats['views_today'],
                'ti-eye',
                'blue'
            ); ?>

        </div>

        <!-- ============================================================
             CHART + ACTIVE CAMPAIGNS
        ============================================================ -->
        <div class="cmc-grid cmc-grid--main cmc-mb-5">

            <!-- Sales chart -->
            <div class="cmc-card">
                <div class="cmc-card__header">
                    <div>
                        <div class="cmc-card__title"><?php esc_html_e('فروش کمپین', 'campaignchi'); ?></div>
                        <div class="cmc-card__subtitle"><?php esc_html_e('۷ روز اخیر', 'campaignchi'); ?></div>
                    </div>
                    <a href="<?php echo esc_url(\Msi\Campaignchi\Admin\AdminRouter::url('reports')); ?>" class="cmc-card__action">
                        <?php esc_html_e('گزارش کامل', 'campaignchi'); ?>
                    </a>
                </div>

                <?php if (array_sum(array_column($chart, 'value')) <= 0): ?>
                    <div class="cmc-empty" style="padding:var(--cmc-space-8) 0">
                        <div class="cmc-empty__icon"><i class="ti ti-chart-line"></i></div>
                        <div class="cmc-empty__title"><?php esc_html_e('هنوز فروشی ثبت نشده', 'campaignchi'); ?></div>
                        <div class="cmc-empty__desc"><?php esc_html_e('وقتی محصولات کمپین فروخته شوند، نمودار اینجا نمایش داده می‌شود.', 'campaignchi'); ?></div>
                    </div>
                <?php else: ?>
                    <div class="cmc-chart-bars">
                        <div class="cmc-chart-bars__grid">
                            <span></span><span></span><span></span><span></span>
                        </div>
                        <div class="cmc-chart-bars__bars">
                            <?php foreach ($chart as $bar): ?>
                                <div class="cmc-chart-bar<?php echo $bar['is_today'] ? ' is-today' : ''; ?>"
                                     style="--h:<?php echo max(4, (int) $bar['percent']); ?>%"
                                     data-label="<?php echo esc_attr($bar['label']); ?>"
                                     data-val="<?php echo esc_attr($bar['value_label']); ?>"></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Active campaigns -->
            <div class="cmc-card">
                <div class="cmc-card__header">
                    <div class="cmc-card__title"><?php esc_html_e('کمپین‌های فعال', 'campaignchi'); ?></div>
                    <a href="<?php echo esc_url(\Msi\Campaignchi\Admin\AdminRouter::url('campaigns')); ?>" class="cmc-card__action">
                        <?php esc_html_e('همه', 'campaignchi'); ?>
                    </a>
                </div>

                <?php if (empty($activeCampaigns)): ?>
                    <div class="cmc-empty" style="padding:var(--cmc-space-6) 0">
                        <div class="cmc-empty__icon"><i class="ti ti-bolt-off"></i></div>
                        <div class="cmc-empty__title"><?php esc_html_e('کمپین فعالی وجود ندارد', 'campaignchi'); ?></div>
                        <a href="<?php echo esc_url(\Msi\Campaignchi\Admin\AdminRouter::url('campaigns', ['action' => 'new'])); ?>" class="cmc-btn cmc-btn--primary cmc-btn--sm">
                            <i class="ti ti-plus"></i>
                            <?php esc_html_e('ساخت کمپین', 'campaignchi'); ?>
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($activeCampaigns as $row): $campaign = $row['campaign']; ?>
                        <div class="cmc-camp-item">
                            <div class="cmc-camp-item__thumb <?php echo $campaign->type === 'flash_sale' ? 'cmc-camp-item__thumb--flash' : 'cmc-camp-item__thumb--primary'; ?>">
                                <i class="ti <?php echo $campaign->type === 'flash_sale' ? 'ti-bolt' : 'ti-star'; ?>"></i>
                            </div>
                            <div class="cmc-camp-item__body">
                                <div class="cmc-camp-item__title-row">
                                    <span class="cmc-camp-item__name"><?php echo esc_html($campaign->title); ?></span>
                                    <?php if ($row['is_urgent']): ?>
                                        <span class="cmc-badge cmc-badge--flash">
                                            <span class="cmc-badge__dot"></span> <?php esc_html_e('فوری', 'campaignchi'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="cmc-badge cmc-badge--active">
                                            <span class="cmc-badge__dot"></span> <?php esc_html_e('فعال', 'campaignchi'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="cmc-camp-item__meta"><?php echo esc_html($row['meta']); ?></div>

                                <?php if ($row['stock_percent'] !== null): ?>
                                    <div class="cmc-progress" style="margin-top:8px;">
                                        <div class="cmc-progress__meta">
                                            <span><?php esc_html_e('موجودی', 'campaignchi'); ?></span>
                                            <span class="cmc-progress__meta-value">
                                                <?php echo esc_html(JalaliHelper::toPersianNums((string) $row['stock_percent'])); ?>٪
                                            </span>
                                        </div>
                                        <div class="cmc-progress__track">
                                            <div class="cmc-progress__fill <?php echo $row['stock_percent'] < 30 ? 'cmc-progress__fill--accent' : 'cmc-progress__fill--success'; ?>"
                                                 style="width:<?php echo (int) $row['stock_percent']; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>

        <!-- ============================================================
             TABLE + ACTIVITY FEED
        ============================================================ -->
        <div class="cmc-grid cmc-grid--2">

            <!-- Top campaign products -->
            <div class="cmc-card cmc-card--flush">
                <div class="cmc-card__header" style="padding: var(--cmc-space-5) var(--cmc-space-5) 0;">
                    <div class="cmc-card__title"><?php esc_html_e('پرفروش‌ترین محصولات کمپین امروز', 'campaignchi'); ?></div>
                </div>

                <?php if (empty($topProducts)): ?>
                    <div class="cmc-empty" style="padding:var(--cmc-space-6) var(--cmc-space-5)">
                        <div class="cmc-empty__icon"><i class="ti ti-package-off"></i></div>
                        <div class="cmc-empty__title"><?php esc_html_e('هنوز فروشی ثبت نشده', 'campaignchi'); ?></div>
                        <div class="cmc-empty__desc"><?php esc_html_e('پس از اولین خرید از محصولات کمپین اینجا نمایش داده می‌شود.', 'campaignchi'); ?></div>
                    </div>
                <?php else: ?>
                    <div class="cmc-table-wrap">
                        <table class="cmc-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('محصول', 'campaignchi'); ?></th>
                                    <th class="cmc-table__cell--center"><?php esc_html_e('فروش', 'campaignchi'); ?></th>
                                    <th><?php esc_html_e('قیمت', 'campaignchi'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topProducts as $p): ?>
                                    <tr>
                                        <td class="cmc-table__cell--bold">
                                            <?php echo esc_html($p['name']); ?>
                                            <div class="cmc-table__cell--muted"><?php echo esc_html($p['campaign_title']); ?></div>
                                        </td>
                                        <td class="cmc-table__cell--center">
                                            <span class="cmc-badge cmc-badge--active"><?php echo esc_html($p['qty']); ?></span>
                                        </td>
                                        <td>
                                            <span class="cmc-table__cell--price"><?php echo $p['price']; // phpcs:ignore -- wc_price() returns escaped HTML ?></span>
                                            <?php if ($p['regular_price']): ?>
                                                <span class="cmc-table__cell--strike"><?php echo $p['regular_price']; // phpcs:ignore -- wc_price() returns escaped HTML ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Activity feed -->
            <div class="cmc-card">
                <div class="cmc-card__header">
                    <div class="cmc-card__title"><?php esc_html_e('فعالیت‌های اخیر', 'campaignchi'); ?></div>
                </div>

                <?php if (empty($activity)): ?>
                    <div class="cmc-empty" style="padding:var(--cmc-space-6) 0">
                        <div class="cmc-empty__icon"><i class="ti ti-history"></i></div>
                        <div class="cmc-empty__title"><?php esc_html_e('فعالیتی ثبت نشده', 'campaignchi'); ?></div>
                    </div>
                <?php else: ?>
                    <?php foreach ($activity as $item): ?>
                        <div class="cmc-activity-item">
                            <div class="cmc-activity-item__icon <?php echo esc_attr($item['icon_class']); ?>">
                                <i class="ti <?php echo esc_attr($item['ti']); ?>"></i>
                            </div>
                            <div>
                                <div class="cmc-activity-item__text"><?php echo esc_html($item['text']); ?></div>
                                <div class="cmc-activity-item__time"><?php echo esc_html($item['time_label']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>

        <?php
    }

    // -------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------

    private function analytics(): AnalyticsService
    {
        return Application::getInstance()->make(AnalyticsService::class);
    }

    /**
     * @param array{value:string, direction:string, change_label:string} $stat
     */
    private function renderStatCard(string $label, array $stat, string $icon, string $color): void
    {
        $directionClass = "cmc-stat-card__change--{$stat['direction']}";
        $directionIcon  = match ($stat['direction']) {
            'up'   => 'ti-arrow-up',
            'down' => 'ti-arrow-down',
            default => 'ti-minus',
        };
        ?>
        <div class="cmc-stat-card">
            <div class="cmc-stat-card__header">
                <span class="cmc-stat-card__label"><?php echo esc_html($label); ?></span>
                <span class="cmc-stat-card__icon cmc-stat-card__icon--<?php echo esc_attr($color); ?>">
                    <i class="ti <?php echo esc_attr($icon); ?>"></i>
                </span>
            </div>
            <div class="cmc-stat-card__value"><?php echo esc_html($stat['value']); ?></div>
            <div class="cmc-stat-card__change <?php echo esc_attr($directionClass); ?>">
                <i class="ti <?php echo esc_attr($directionIcon); ?>" style="font-size:11px;"></i>
                <?php echo esc_html($stat['change_label']); ?>
            </div>
        </div>
        <?php
    }
}