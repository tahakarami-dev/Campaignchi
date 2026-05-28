<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Layouts;

use Msi\Campaignchi\Admin\AdminRouter;

/**
 * Panel Layout
 *
 * Outputs a full standalone HTML page — completely replacing
 * the WordPress admin shell.
 *
 * What this does:
 *  - Sends its own <!DOCTYPE html> ... </html>
 *  - Loads our design system (base.css + components.css + panel.js)
 *  - Renders sidebar, topbar, and the current page content
 *  - WordPress never gets to output its own UI
 *
 * @package Msi\Campaignchi\Admin\Layouts
 */
class PanelLayout
{
    public function __construct(private AdminRouter $router) {}

    /**
     * Output the full page HTML and exit.
     * Called from AdminServiceProvider::maybeRenderPanel().
     */
    public function render(): void
    {
        $page       = $this->router->resolve();
        $activeSlug = $this->router->currentSlug();
        $user       = wp_get_current_user();
        $userInitial = mb_substr($user->display_name, 0, 1, 'UTF-8');

        // Build Jalali-like date label (uses WP locale)
        $dateLabel = wp_date('l، j F Y');

        ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($page->title()); ?> — <?php esc_html_e('کمپین‌چی', 'campaignchi'); ?></title>

    <!-- Prevent WP admin from injecting anything -->
    <?php wp_head(); // Required for nonce / AJAX URL scripts to work ?>

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

    <!-- Suppress all WordPress admin styles -->
    <style>
        /* Hard reset: remove WP admin body styles that may bleed through wp_head() */
        body { margin: 0 !important; padding: 0 !important; background: transparent !important; }
        #adminmenuwrap, #adminmenuback, #wpfooter { display: none !important; }
        #wpcontent, #wpbody, #wpbody-content { margin: 0 !important; padding: 0 !important; float: none !important; }
    </style>
</head>
<body class="cmc-body">

<!--
    ============================================================
    CMC Admin Panel — Full Custom Layout
    WordPress chrome is completely suppressed.
    ============================================================
-->

<div class="cmc-panel" id="cmc-app">

    <!-- ======================================================
         SIDEBAR
    ====================================================== -->
    <aside class="cmc-sidebar" id="cmc-sidebar">

        <!-- Logo -->
        <div class="cmc-sidebar__logo">
            <div class="cmc-sidebar__logo-wrap">
                <div class="cmc-sidebar__logo-icon">
                    <i class="ti ti-bolt" style="color:#fff; font-size:18px;"></i>
                </div>
                <div>
                    <div class="cmc-sidebar__logo-name"><?php esc_html_e('کمپین‌چی', 'campaignchi'); ?></div>
                    <div class="cmc-sidebar__logo-sub"><?php esc_html_e('افزونه WooCommerce', 'campaignchi'); ?></div>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="cmc-sidebar__nav" aria-label="<?php esc_attr_e('منوی اصلی', 'campaignchi'); ?>">

            <?php $this->renderNavItem('dashboard',  'ti-layout-dashboard', 'داشبورد',     $activeSlug); ?>
            <?php $this->renderNavItem('campaigns',  'ti-bolt',             'کمپین‌ها',    $activeSlug, '۴'); ?>
            <?php $this->renderNavItem('schedule',   'ti-calendar-time',    'زمان‌بندی',   $activeSlug, null, true); ?>

            <p class="cmc-nav__section"><?php esc_html_e('تنظیمات', 'campaignchi'); ?></p>

            <?php $this->renderNavItem('templates',   'ti-layout-2',  'قالب‌ها', $activeSlug); ?>
            <?php $this->renderNavItem('appearance',  'ti-palette',   'ظاهر',    $activeSlug); ?>
            <?php $this->renderNavItem('reports',     'ti-chart-bar', 'گزارش‌ها',$activeSlug); ?>

            <p class="cmc-nav__section"><?php esc_html_e('سیستم', 'campaignchi'); ?></p>

            <?php $this->renderNavItem('settings',    'ti-settings',      'تنظیمات', $activeSlug); ?>

        </nav>

        <!-- User profile -->
        <div class="cmc-sidebar__footer">
            <div class="cmc-sidebar__user" id="cmc-user-trigger">
                <div class="cmc-avatar cmc-avatar--md cmc-avatar--purple">
                    <?php echo esc_html($userInitial); ?>
                </div>
                <div class="cmc-sidebar__user-info">
                    <div class="cmc-sidebar__user-name">
                        <?php echo esc_html($user->display_name); ?>
                    </div>
                    <div class="cmc-sidebar__user-role">
                        <?php esc_html_e('مدیر سیستم', 'campaignchi'); ?>
                    </div>
                </div>
                <i class="ti ti-chevron-down cmc-sidebar__user-arrow"></i>
            </div>
        </div>

    </aside>
    <!-- /SIDEBAR -->

    <!-- ======================================================
         MAIN AREA
    ====================================================== -->
    <div class="cmc-main">

        <!-- TOPBAR -->
        <header class="cmc-topbar" id="cmc-topbar">
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

                <button class="cmc-topbar__icon-btn" aria-label="<?php esc_attr_e('جستجو', 'campaignchi'); ?>">
                    <i class="ti ti-search"></i>
                </button>

                <a href="<?php echo esc_url(AdminRouter::url('campaigns', ['action' => 'new'])); ?>"
                   class="cmc-btn cmc-btn--secondary cmc-btn--sm">
                    <i class="ti ti-plus" style="font-size:14px;"></i>
                    <?php esc_html_e('کمپین جدید', 'campaignchi'); ?>
                </a>

                <a href="<?php echo esc_url(AdminRouter::url('campaigns', ['action' => 'flash'])); ?>"
                   class="cmc-btn cmc-btn--accent cmc-btn--sm">
                    <i class="ti ti-bolt" style="font-size:14px;"></i>
                    <?php esc_html_e('فلش سیل', 'campaignchi'); ?>
                </a>

                <!-- Back to WP Admin (escape hatch) -->
                <a href="<?php echo esc_url(admin_url()); ?>"
                   class="cmc-topbar__icon-btn"
                   title="<?php esc_attr_e('بازگشت به پیشخوان وردپرس', 'campaignchi'); ?>">
                    <i class="ti ti-arrow-back-up"></i>
                </a>

            </div>
        </header>
        <!-- /TOPBAR -->

        <!-- PAGE CONTENT -->
        <main class="cmc-content" id="cmc-content">
            <div class="cmc-content-inner">
                <?php $page->render(); ?>
            </div>
        </main>
        <!-- /PAGE CONTENT -->

    </div>
    <!-- /MAIN -->

</div>
<!-- /cmc-panel -->

<!-- Toast container -->
<div class="cmc-toast-container" id="cmc-toasts" aria-live="polite"></div>

<!-- CMC Panel JS -->
<script>
    // Pass WP data to our JS (nonce, AJAX URL, etc.)
    window.CMC_DATA = {
        ajaxUrl : <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
        nonce   : <?php echo wp_json_encode(wp_create_nonce('cmc_admin')); ?>,
        version : <?php echo wp_json_encode(CMC_VERSION); ?>,
        page    : <?php echo wp_json_encode($activeSlug); ?>
    };
</script>
<script src="<?php echo esc_url(CMC_ASSETS_URL . 'js/panel.js'); ?>?v=<?php echo CMC_VERSION; ?>"></script>

<?php wp_footer(); // Required for some WP internals (heartbeat, etc.) ?>
</body>
</html>
        <?php
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /**
     * Render a single navigation item.
     *
     * @param string      $slug      Route slug
     * @param string      $icon      Tabler icon class (e.g. "ti-bolt")
     * @param string      $label     Display label (Persian)
     * @param string      $active    Currently active slug
     * @param string|null $badge     Optional badge text
     * @param bool        $accentBadge Use accent color for badge
     */
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
        <a href="<?php echo $url; ?>" class="<?php echo $classes; ?>">
            <span class="cmc-nav__item-icon"><i class="ti <?php echo esc_attr($icon); ?>"></i></span>
            <span class="cmc-nav__item-label"><?php echo esc_html(__($label, 'campaignchi')); ?></span>
            <?php if ($badge): ?>
                <span class="cmc-nav__item-badge <?php echo $accentBadge ? 'cmc-nav__item-badge--accent' : ''; ?>">
                    <?php echo esc_html($badge); ?>
                </span>
            <?php endif; ?>
        </a>
        <?php
    }
}
