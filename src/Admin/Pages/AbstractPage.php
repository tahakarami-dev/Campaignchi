<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract Page
 *
 * Base class for all admin panel pages.
 * Each page provides:
 *  - title()   → shown in topbar
 *  - render()  → outputs the page HTML (no layout — layout wraps it)
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
abstract class AbstractPage
{
    /**
     * Page title (shown in topbar).
     */
    abstract public function title(): string;

    /**
     * Render page content HTML.
     * Should echo HTML — no return value.
     */
    abstract public function render(): void;
}
