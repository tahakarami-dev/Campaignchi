<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Controllers;

use Msi\Campaignchi\Admin\AdminRouter;
use Msi\Campaignchi\Admin\Pages\ReportsPage;
use Msi\Campaignchi\Analytics\Services\ReportService;

/**
 * Reports AJAX Controller
 *
 * Backs the admin "Reports" page:
 *   - cmc_get_report_data : returns the report as pre-rendered HTML
 *     fragments (KPIs, chart, campaigns table, top products) so the page
 *     can load them progressively behind skeletons. PHP stays the single
 *     source of truth for markup + escaping; JS only orchestrates.
 *   - cmc_export_report   : streams the order-centric CSV download.
 *
 * Security mirrors CampaignController: a nonce (from GET or POST via
 * check_ajax_referer) plus a capability check.
 *
 * @package Msi\Campaignchi\Analytics\Controllers
 */
class ReportsAjaxController
{
    public function __construct(
        private ReportService $reports
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_cmc_get_report_data', [$this, 'getReportData']);
        add_action('wp_ajax_cmc_export_report', [$this, 'exportReport']);
    }

    /**
     * Build the report for the requested range and return rendered HTML
     * fragments for each region of the page.
     */
    public function getReportData(): void
    {
        $this->guard();

        $range  = $this->resolveRangeFromRequest();
        $report = $this->reports->getReport($range);

        $page    = new ReportsPage();
        $hasData = $report['summary']['orders'] > 0 || $report['summary']['impressions'] > 0;

        $this->json([
            'has_data'    => $hasData,
            'range_key'   => $range['key'],
            'range_label' => $range['label'],
            'export_url'  => $this->exportUrl($range),
            'kpis'        => $page->renderKpisFragment($report['summary']),
            'chart'       => $page->renderChartFragment($report['series'], $hasData),
            'campaigns'   => $page->renderCampaignsFragment($report['campaigns']),
            'products'    => $page->renderProductsFragment($report['top_products']),
        ]);
    }

    /**
     * Resolve the range, build the report, and stream the order-centric CSV
     * with a UTF-8 BOM so Excel detects the encoding correctly.
     */
    public function exportReport(): void
    {
        $this->guard();

        $range  = $this->resolveRangeFromRequest();
        $report = $this->reports->getReport($range);
        $rows   = $this->reports->csvRows($report, $range);

        $this->stream($rows, $this->reports->exportFilename($range));
    }

    // -------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------

    /** @return array{key:string, start:string, end:string, label:string} */
    private function resolveRangeFromRequest(): array
    {
        $rangeKey = isset($_GET['range']) ? sanitize_key((string) $_GET['range']) : 'last7';
        $from     = isset($_GET['from'])  ? sanitize_text_field((string) $_GET['from']) : null;
        $to       = isset($_GET['to'])    ? sanitize_text_field((string) $_GET['to'])   : null;

        return $this->reports->resolveRange($rangeKey, $from, $to);
    }

    private function exportUrl(array $range): string
    {
        return add_query_arg(
            [
                'action' => 'cmc_export_report',
                'nonce'  => wp_create_nonce('cmc_admin'),
                'range'  => $range['key'],
                'from'   => $range['key'] === 'custom' ? $range['start'] : '',
                'to'     => $range['key'] === 'custom' ? $range['end']   : '',
            ],
            admin_url('admin-ajax.php')
        );
    }

    private function guard(): void
    {
        if (!check_ajax_referer('cmc_admin', 'nonce', false)) {
            wp_die(esc_html__('درخواست نامعتبر.', 'campaignchi'), '', ['response' => 403]);
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی مجاز نیست.', 'campaignchi'), '', ['response' => 403]);
        }
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = 200): never
    {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($data);
        exit;
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function stream(array $rows, string $filename): never
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }
}