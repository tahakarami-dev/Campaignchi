<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

/**
 * Dashboard Page
 *
 * Main overview: stat cards, sales chart, active campaigns, activity feed.
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
        ?>

        <!-- ============================================================
             STAT CARDS ROW
        ============================================================ -->
        <div class="cmc-grid cmc-grid--4 cmc-mb-5">

            <div class="cmc-stat-card">
                <div class="cmc-stat-card__header">
                    <span class="cmc-stat-card__label"><?php esc_html_e('کمپین فعال', 'campaignchi'); ?></span>
                    <span class="cmc-stat-card__icon cmc-stat-card__icon--purple">
                        <i class="ti ti-bolt"></i>
                    </span>
                </div>
                <div class="cmc-stat-card__value">۴</div>
                <div class="cmc-stat-card__change cmc-stat-card__change--up">
                    <i class="ti ti-arrow-up" style="font-size:11px;"></i>
                    <?php esc_html_e('۲ تا بیشتر از هفته قبل', 'campaignchi'); ?>
                </div>
            </div>

            <div class="cmc-stat-card">
                <div class="cmc-stat-card__header">
                    <span class="cmc-stat-card__label"><?php esc_html_e('فروش امروز', 'campaignchi'); ?></span>
                    <span class="cmc-stat-card__icon cmc-stat-card__icon--orange">
                        <i class="ti ti-chart-line"></i>
                    </span>
                </div>
                <div class="cmc-stat-card__value">۱۲.۴M</div>
                <div class="cmc-stat-card__change cmc-stat-card__change--up">
                    <i class="ti ti-arrow-up" style="font-size:11px;"></i>
                    <?php esc_html_e('۱۸٪ رشد', 'campaignchi'); ?>
                </div>
            </div>

            <div class="cmc-stat-card">
                <div class="cmc-stat-card__header">
                    <span class="cmc-stat-card__label"><?php esc_html_e('نرخ تبدیل', 'campaignchi'); ?></span>
                    <span class="cmc-stat-card__icon cmc-stat-card__icon--green">
                        <i class="ti ti-click"></i>
                    </span>
                </div>
                <div class="cmc-stat-card__value">۶.۸٪</div>
                <div class="cmc-stat-card__change cmc-stat-card__change--up">
                    <i class="ti ti-arrow-up" style="font-size:11px;"></i>
                    <?php esc_html_e('۱.۲٪ افزایش', 'campaignchi'); ?>
                </div>
            </div>

            <div class="cmc-stat-card">
                <div class="cmc-stat-card__header">
                    <span class="cmc-stat-card__label"><?php esc_html_e('بازدید امروز', 'campaignchi'); ?></span>
                    <span class="cmc-stat-card__icon cmc-stat-card__icon--blue">
                        <i class="ti ti-eye"></i>
                    </span>
                </div>
                <div class="cmc-stat-card__value">۸,۳۴۱</div>
                <div class="cmc-stat-card__change cmc-stat-card__change--down">
                    <i class="ti ti-arrow-down" style="font-size:11px;"></i>
                    <?php esc_html_e('۳٪ کاهش', 'campaignchi'); ?>
                </div>
            </div>

        </div>

        <!-- ============================================================
             CHART + ACTIVE CAMPAIGNS
        ============================================================ -->
        <div class="cmc-grid cmc-grid--main cmc-mb-5">

            <!-- Sales chart -->
            <div class="cmc-card">
                <div class="cmc-card__header">
                    <div>
                        <div class="cmc-card__title"><?php esc_html_e('عملکرد فروش', 'campaignchi'); ?></div>
                        <div class="cmc-card__subtitle"><?php esc_html_e('۷ روز اخیر', 'campaignchi'); ?></div>
                    </div>
                    <a href="<?php echo esc_url(\Msi\Campaignchi\Admin\AdminRouter::url('reports')); ?>" class="cmc-card__action">
                        <?php esc_html_e('گزارش کامل', 'campaignchi'); ?>
                    </a>
                </div>
                <div class="cmc-chart-bars">
                    <div class="cmc-chart-bars__grid">
                        <span></span><span></span><span></span><span></span>
                    </div>
                    <div class="cmc-chart-bars__bars">
                        <div class="cmc-chart-bar" style="--h:45%" data-label="شن" data-val="۴.۱M"></div>
                        <div class="cmc-chart-bar" style="--h:60%" data-label="یک" data-val="۵.۴M"></div>
                        <div class="cmc-chart-bar" style="--h:42%" data-label="دو" data-val="۳.۸M"></div>
                        <div class="cmc-chart-bar is-today" style="--h:75%" data-label="سه" data-val="۶.۸M"></div>
                        <div class="cmc-chart-bar" style="--h:55%" data-label="چه" data-val="۴.۹M"></div>
                        <div class="cmc-chart-bar" style="--h:85%" data-label="پن" data-val="۷.۶M"></div>
                        <div class="cmc-chart-bar is-accent" style="--h:70%" data-label="جم" data-val="۶.۳M"></div>
                    </div>
                </div>
            </div>

            <!-- Active campaigns -->
            <div class="cmc-card">
                <div class="cmc-card__header">
                    <div class="cmc-card__title"><?php esc_html_e('کمپین‌های فعال', 'campaignchi'); ?></div>
                    <a href="<?php echo esc_url(\Msi\Campaignchi\Admin\AdminRouter::url('campaigns')); ?>" class="cmc-card__action">
                        <?php esc_html_e('همه', 'campaignchi'); ?>
                    </a>
                </div>

                <div class="cmc-camp-item">
                    <div class="cmc-camp-item__thumb cmc-camp-item__thumb--flash">
                        <i class="ti ti-bolt"></i>
                    </div>
                    <div class="cmc-camp-item__body">
                        <div class="cmc-camp-item__title-row">
                            <span class="cmc-camp-item__name"><?php esc_html_e('فلش سیل یلدا', 'campaignchi'); ?></span>
                            <span class="cmc-badge cmc-badge--flash"><span class="cmc-badge__dot"></span> <?php esc_html_e('فوری', 'campaignchi'); ?></span>
                        </div>
                        <div class="cmc-camp-item__meta"><?php esc_html_e('۱۲ محصول · ۲ ساعت مانده', 'campaignchi'); ?></div>
                        <div class="cmc-progress" style="margin-top:8px;">
                            <div class="cmc-progress__meta">
                                <span><?php esc_html_e('موجودی', 'campaignchi'); ?></span>
                                <span class="cmc-progress__meta-value" style="color:var(--cmc-accent)">۳۸٪</span>
                            </div>
                            <div class="cmc-progress__track">
                                <div class="cmc-progress__fill cmc-progress__fill--accent" style="width:38%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="cmc-camp-item">
                    <div class="cmc-camp-item__thumb cmc-camp-item__thumb--primary">
                        <i class="ti ti-tag"></i>
                    </div>
                    <div class="cmc-camp-item__body">
                        <div class="cmc-camp-item__title-row">
                            <span class="cmc-camp-item__name"><?php esc_html_e('تخفیف محصولات دیجیتال', 'campaignchi'); ?></span>
                            <span class="cmc-badge cmc-badge--active"><span class="cmc-badge__dot"></span> <?php esc_html_e('فعال', 'campaignchi'); ?></span>
                        </div>
                        <div class="cmc-camp-item__meta"><?php esc_html_e('۸ محصول · تا ۵ خرداد', 'campaignchi'); ?></div>
                        <div class="cmc-progress" style="margin-top:8px;">
                            <div class="cmc-progress__meta">
                                <span><?php esc_html_e('موجودی', 'campaignchi'); ?></span>
                                <span class="cmc-progress__meta-value" style="color:var(--cmc-success)">۷۲٪</span>
                            </div>
                            <div class="cmc-progress__track">
                                <div class="cmc-progress__fill cmc-progress__fill--success" style="width:72%"></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <!-- ============================================================
             TABLE + ACTIVITY FEED
        ============================================================ -->
        <div class="cmc-grid cmc-grid--2">

            <!-- Top products -->
            <div class="cmc-card cmc-card--flush">
                <div class="cmc-card__header" style="padding: var(--cmc-space-5) var(--cmc-space-5) 0;">
                    <div class="cmc-card__title"><?php esc_html_e('پرفروش‌ترین محصولات', 'campaignchi'); ?></div>
                    <a href="#" class="cmc-card__action"><?php esc_html_e('گزارش کامل', 'campaignchi'); ?></a>
                </div>
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
                            <tr>
                                <td class="cmc-table__cell--bold"><?php esc_html_e('قالب وردپرس Nexus', 'campaignchi'); ?></td>
                                <td class="cmc-table__cell--center"><span class="cmc-badge cmc-badge--active">۱۴۳</span></td>
                                <td><span class="cmc-table__cell--price">۱۸۰K</span> <span class="cmc-table__cell--strike">۳۵۰K</span></td>
                            </tr>
                            <tr>
                                <td class="cmc-table__cell--bold"><?php esc_html_e('افزونه SEO Pro', 'campaignchi'); ?></td>
                                <td class="cmc-table__cell--center"><span class="cmc-badge cmc-badge--primary">۹۷</span></td>
                                <td><span class="cmc-table__cell--price">۹۵K</span> <span class="cmc-table__cell--strike">۱۵۰K</span></td>
                            </tr>
                            <tr>
                                <td class="cmc-table__cell--bold"><?php esc_html_e('دوره آموزش Laravel', 'campaignchi'); ?></td>
                                <td class="cmc-table__cell--center"><span class="cmc-badge cmc-badge--flash">۶۴</span></td>
                                <td><span class="cmc-table__cell--price">۴۵۰K</span> <span class="cmc-table__cell--strike">۷۵۰K</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Activity feed -->
            <div class="cmc-card">
                <div class="cmc-card__header">
                    <div class="cmc-card__title"><?php esc_html_e('فعالیت‌های اخیر', 'campaignchi'); ?></div>
                </div>
                <div class="cmc-activity-item">
                    <div class="cmc-activity-item__icon cmc-activity-item__icon--flash"><i class="ti ti-bolt"></i></div>
                    <div>
                        <div class="cmc-activity-item__text"><?php esc_html_e('کمپین «فلش سیل یلدا» شروع شد', 'campaignchi'); ?></div>
                        <div class="cmc-activity-item__time"><?php esc_html_e('۱۵ دقیقه پیش', 'campaignchi'); ?></div>
                    </div>
                </div>
                <div class="cmc-activity-item">
                    <div class="cmc-activity-item__icon cmc-activity-item__icon--success"><i class="ti ti-check"></i></div>
                    <div>
                        <div class="cmc-activity-item__text"><?php esc_html_e('۱۲ سفارش جدید ثبت شد', 'campaignchi'); ?></div>
                        <div class="cmc-activity-item__time"><?php esc_html_e('۴۵ دقیقه پیش', 'campaignchi'); ?></div>
                    </div>
                </div>
                <div class="cmc-activity-item">
                    <div class="cmc-activity-item__icon cmc-activity-item__icon--primary"><i class="ti ti-plus"></i></div>
                    <div>
                        <div class="cmc-activity-item__text"><?php esc_html_e('محصول «دوره React» اضافه شد', 'campaignchi'); ?></div>
                        <div class="cmc-activity-item__time"><?php esc_html_e('۵ ساعت پیش', 'campaignchi'); ?></div>
                    </div>
                </div>
            </div>

        </div>

        <?php
    }
}
