<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Layouts;

use Msi\Campaignchi\Admin\AdminRouter;
use Msi\Campaignchi\Helpers\JalaliHelper;

/**
 * Panel Layout
 *
 * Outputs a full standalone HTML page — completely replacing
 * the WordPress admin shell.
 *
 * @package Msi\Campaignchi\Admin\Layouts
 */
class PanelLayout
{
    public function __construct(private AdminRouter $router) {}

    public function render(): void
    {
        $page        = $this->router->resolve();
        $activeSlug  = $this->router->currentSlug();
        $user        = wp_get_current_user();
        $userInitial = mb_substr($user->display_name, 0, 1, 'UTF-8');
        // ⚠️ تاریخ شمسی زیر عنوان صفحه — مثال خروجی: «دوشنبه، ۲۵ خرداد ۱۴۰۵»
        // قبلاً اینجا wp_date('l، j F Y') بود که تاریخ را به‌صورت میلادی
        // (با نام روز/ماه فارسی) نمایش می‌داد. toFullDisplay() بدون آرگومان
        // یعنی «اکنون بر اساس تایم‌زون سایت» و آن را به شمسی تبدیل می‌کند.
        $dateLabel   = JalaliHelper::toFullDisplay();
        $isAdmin     = is_admin_bar_showing();

?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($page->title()); ?> — <?php esc_html_e('کمپین‌چی', 'campaignchi'); ?></title>

            <?php wp_head(); ?>

            <!-- Google Fonts: Vazirmatn -->
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">

            <!-- Tabler Icons -->
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

            <!-- CMC Design System -->
            <link rel="stylesheet" href="<?php echo esc_url(CMC_ASSETS_URL . 'css/base.css'); ?>?v=<?php echo CMC_VERSION; ?>">
            <link rel="stylesheet" href="<?php echo esc_url(CMC_ASSETS_URL . 'css/components.css'); ?>?v=<?php echo CMC_VERSION; ?>">
            <link rel="stylesheet" href="<?php echo esc_url(CMC_ASSETS_URL . 'css/panel.css'); ?>?v=<?php echo CMC_VERSION; ?>">
            <!-- تاریخ‌گیر شمسی اختصاصی -->
            <link rel="stylesheet" href="<?php echo esc_url(CMC_ASSETS_URL . 'css/datepicker.css'); ?>?v=<?php echo CMC_VERSION; ?>">
            <?php if ($activeSlug === 'templates'): ?>
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.css">
                <link rel="stylesheet" href="<?php echo esc_url(CMC_URL . 'assets/css/slider.css'); ?>">
            <?php endif; ?>

            <style>
                body {
                    margin: 0 !important;
                    padding: 0 !important;
                    background: #F7F8FC !important;
                }

                #adminmenuback,
                #wpfooter,
                #wpbody,
                #wpcontent,
                .update-nag,
                .wp-pointer {
                    display: none !important;
                }

                <?php if ($isAdmin): ?>.cmc-body-wrap {
                    margin-top: 32px;
                }

                @media screen and (max-width: 782px) {
                    .cmc-body-wrap {
                        margin-top: 46px;
                    }
                }

                <?php endif; ?>
            </style>
        </head>

        <body class="cmc-body <?php echo $isAdmin ? 'has-adminbar' : ''; ?>">

            <div class="cmc-body-wrap">
                <div class="cmc-panel" id="cmc-app">

                    <!-- ===== SIDEBAR ===== -->
                    <aside class="cmc-sidebar" id="cmc-sidebar">

                        <div class="cmc-sidebar__logo">
                            <div class="cmc-sidebar__logo-wrap">
                                <div class="cmc-sidebar__logo-icon">
                                    <img src="<?php echo esc_url(CMC_ASSETS_URL . 'images/logo.png'); ?>"
                                        alt="<?php esc_attr_e('کمپین‌چی', 'campaignchi'); ?>"
                                        width="28" height="28" style="display:block;">
                                </div>
                                <div>
                                    <div class="cmc-sidebar__logo-name"><?php esc_html_e('کمپین‌چی', 'campaignchi'); ?></div>
                                    <div class="cmc-sidebar__logo-sub"><?php esc_html_e('افزونه WooCommerce', 'campaignchi'); ?></div>
                                </div>
                            </div>
                        </div>

                        <nav class="cmc-sidebar__nav" aria-label="<?php esc_attr_e('منوی اصلی', 'campaignchi'); ?>">
                            <?php $this->renderNavItem('dashboard',  'ti-layout-dashboard', 'داشبورد',   $activeSlug); ?>
                            <?php $this->renderNavItem('campaigns',  'ti-bolt',             'کمپین‌ها',  $activeSlug, '۴'); ?>

                            <p class="cmc-nav__section"><?php esc_html_e('تنظیمات', 'campaignchi'); ?></p>

                            <?php $this->renderNavItem('templates',  'ti-layout-2',  'قالب‌ها',  $activeSlug); ?>
                            <?php $this->renderNavItem('appearance', 'ti-palette',   'ظاهر',     $activeSlug); ?>
                            <?php $this->renderNavItem('reports',    'ti-chart-bar', 'گزارش‌ها', $activeSlug); ?>

                            <p class="cmc-nav__section"><?php esc_html_e('سیستم', 'campaignchi'); ?></p>

                            <?php $this->renderNavItem('settings', 'ti-settings', 'تنظیمات', $activeSlug); ?>
                        </nav>

                        <div class="cmc-sidebar__footer">
                            <div class="cmc-dropdown" style="width:100%">
                                <div class="cmc-sidebar__user" id="cmc-user-trigger" data-cmc-dropdown-trigger>
                                    <div class="cmc-avatar cmc-avatar--md cmc-avatar--purple">
                                        <?php echo esc_html($userInitial); ?>
                                    </div>
                                    <div class="cmc-sidebar__user-info">
                                        <div class="cmc-sidebar__user-name"><?php echo esc_html($user->display_name); ?></div>
                                        <div class="cmc-sidebar__user-role"><?php esc_html_e('مدیر سیستم', 'campaignchi'); ?></div>
                                    </div>
                                    <i class="ti ti-chevron-down cmc-sidebar__user-arrow"></i>
                                </div>

                                <div class="cmc-dropdown__menu cmc-dropdown__menu--top">
                                    <a href="<?php echo esc_url(admin_url()); ?>" class="cmc-dropdown__item">
                                        <i class="ti ti-layout-dashboard"></i>
                                        <?php esc_html_e('بازگشت به پیشخوان وردپرس', 'campaignchi'); ?>
                                    </a>
                                    <div class="cmc-dropdown__divider"></div>
                                    <a href="<?php echo esc_url(wp_logout_url(admin_url())); ?>" class="cmc-dropdown__item cmc-dropdown__item--danger">
                                        <i class="ti ti-logout"></i>
                                        <?php esc_html_e('خروج از حساب', 'campaignchi'); ?>
                                    </a>
                                </div>
                            </div>
                        </div>

                    </aside>
                    <!-- /SIDEBAR -->

                    <!-- ===== MAIN ===== -->
                    <div class="cmc-main">

                        <header class="cmc-topbar" id="cmc-topbar">

                            <button class="cmc-topbar__icon-btn cmc-topbar__hamburger"
                                id="cmc-hamburger"
                                aria-label="<?php esc_attr_e('منو', 'campaignchi'); ?>">
                                <i class="ti ti-menu-2"></i>
                            </button>

                            <div class="cmc-topbar__title">
                                <span class="cmc-topbar__page"><?php echo esc_html($page->title()); ?></span>
                                <span class="cmc-topbar__sub"><?php echo esc_html($dateLabel); ?></span>
                            </div>

                            <div class="cmc-topbar__spacer"></div>

                            <div class="cmc-topbar__actions">
                                <button class="cmc-topbar__icon-btn" aria-label="<?php esc_attr_e('اعلان‌ها', 'campaignchi'); ?>">
                                    <i class="ti ti-bell"></i>
                                    <span class="cmc-dot"></span>
                                </button>

                                <a href="<?php echo esc_url(AdminRouter::url('campaigns', ['action' => 'new'])); ?>"
                                    class="cmc-btn cmc-btn--secondary cmc-btn--sm">
                                    <i class="ti ti-plus" style="font-size:14px;"></i>
                                    <?php esc_html_e('کمپین جدید', 'campaignchi'); ?>
                                </a>
                            </div>

                        </header>

                        <main class="cmc-content" id="cmc-content">
                            <div class="cmc-content-inner">
                                <?php $page->render(); ?>
                            </div>
                        </main>

                    </div>
                    <!-- /MAIN -->

                </div>
                <!-- /cmc-panel -->
            </div>
            <!-- /cmc-body-wrap -->

            <!-- Backdrop -->
            <div class="cmc-sidebar-backdrop" id="cmc-backdrop"></div>

            <!-- Toast container -->
            <div class="cmc-toast-container" id="cmc-toasts" aria-live="polite"></div>

            <!-- WP data to JS — باید قبل از همه اسکریپت‌ها باشه -->
            <script>
                window.CMC_DATA = {
                    ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
                    nonce: <?php echo wp_json_encode(wp_create_nonce('cmc_admin')); ?>,
                    version: <?php echo wp_json_encode(CMC_VERSION); ?>,
                    page: <?php echo wp_json_encode($activeSlug); ?>,
                    hasAdminBar: <?php echo wp_json_encode($isAdmin); ?>
                };
            </script>

            <!-- Core scripts — ترتیب مهم است -->
            <script src="<?php echo esc_url(CMC_ASSETS_URL . 'js/panel.js'); ?>?v=<?php echo CMC_VERSION; ?>"></script>
            <script src="<?php echo esc_url(CMC_ASSETS_URL . 'js/datepicker.js'); ?>?v=<?php echo CMC_VERSION; ?>"></script>

            <?php if ($activeSlug === 'templates'): ?>
                <script src="https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.js"></script>
                <script src="<?php echo esc_url(CMC_URL . 'assets/js/frontend-slider.js'); ?>"></script>
            <?php endif; ?>

            <?php
            // اسکریپت‌های مختص هر صفحه
            $pageScripts = [
                'campaigns'  => 'campaigns.js',
                'appearance' => 'appearance-page.js',
                'templates'  => 'templates-admin.js',
            ];
            if (isset($pageScripts[$activeSlug])) :
            ?>
                <script src="<?php echo esc_url(CMC_ASSETS_URL . 'js/' . $pageScripts[$activeSlug]); ?>?v=<?php echo CMC_VERSION; ?>"></script>
            <?php endif; ?>

            <?php wp_footer(); ?>
        </body>

        </html>
    <?php
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function renderNavItem(
        string $slug,
        string $icon,
        string $label,
        string $active,
        ?string $badge = null,
        bool $accentBadge = false
    ): void {
        $isActive = ($slug === $active);
        $classes  = 'cmc-nav__item' . ($isActive ? ' is-active' : '');
        $url      = esc_url(AdminRouter::url($slug));
    ?>
        <a href="<?php echo $url; ?>"
            class="<?php echo $classes; ?>"
            data-label="<?php echo esc_attr(__($label, 'campaignchi')); ?>">
            <span class="cmc-nav__item-icon">
                <i class="ti <?php echo esc_attr($icon); ?>"></i>
            </span>
            <span class="cmc-nav__item-label">
                <?php echo esc_html(__($label, 'campaignchi')); ?>
            </span>
            <?php if ($badge): ?>
                <span class="cmc-nav__item-badge <?php echo $accentBadge ? 'cmc-nav__item-badge--accent' : ''; ?>">
                    <?php echo esc_html($badge); ?>
                </span>
            <?php endif; ?>
        </a>
<?php
    }
}