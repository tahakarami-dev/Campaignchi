<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Controllers;

use Msi\Campaignchi\Analytics\Services\ReportService;

/**
 * Reports AJAX Controller
 *
 * Backs the admin "Reports" page export. The report itself is rendered
 * server-side by ReportsPage (no JSON round-trip needed); this controller
 * exists solely to stream the CSV download.
 *
 * Security mirrors the existing CampaignController pattern: a nonce
 * (checked from either GET or POST via check_ajax_referer) plus a
 * capability check. The download is triggered by a normal link, so the
 * nonce travels as a GET parameter.
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
        add_action('wp_ajax_cmc_export_report', [$this, 'exportReport']);
    }

    /**
     * Resolve the requested range, build the report, and stream it as a
     * UTF-8 CSV with a BOM (so Excel — including Persian Windows builds —
     * detects the encoding correctly and never mojibakes the labels).
     */
    public function exportReport(): void
    {
        if (!check_ajax_referer('cmc_admin', 'nonce', false)) {
            wp_die(esc_html__('درخواست نامعتبر.', 'campaignchi'), '', ['response' => 403]);
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی مجاز نیست.', 'campaignchi'), '', ['response' => 403]);
        }

        $rangeKey = isset($_GET['range']) ? sanitize_key((string) $_GET['range']) : 'last7';
        $from     = isset($_GET['from'])  ? sanitize_text_field((string) $_GET['from']) : null;
        $to       = isset($_GET['to'])    ? sanitize_text_field((string) $_GET['to'])   : null;

        $range  = $this->reports->resolveRange($rangeKey, $from, $to);
        $report = $this->reports->getReport($range);
        $rows   = $this->reports->csvRows($report, $range);

        $this->stream($rows, $this->reports->exportFilename($range));
    }

    /**
     * Emit CSV download headers and write every row, then exit.
     *
     * @param array<int, array<int, string>> $rows
     */
    private function stream(array $rows, string $filename): never
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // UTF-8 BOM — makes Excel open the file in the correct encoding.
        fwrite($output, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }
}
