<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Admin\Pages;

/**
 * AdminPage — Abstract Page Controller
 *
 * Every admin page extends this class and implements:
 * - getTitle():          page title shown in topbar
 * - getTopbarActions():  HTML string for topbar right-side buttons
 * - render():            outputs the page content HTML
 *
 * @package Msi\Campaignchi\Admin\Pages
 */
abstract class AdminPage
{
    /**
     * Return the page title for the topbar.
     */
    abstract public function getTitle(): string;

    /**
     * Return HTML for topbar action buttons.
     * Use CMC button components. Output must be pre-escaped.
     */
    public function getTopbarActions(): string
    {
        return '';
    }

    /**
     * Render the page content (inside .cmc-content-inner).
     */
    abstract public function render(): void;
}
