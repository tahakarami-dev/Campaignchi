<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Services;

use Msi\Campaignchi\Analytics\Repositories\CampaignSalesRepository;
use Msi\Campaignchi\Campaign\Pricing\CampaignResolver;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign Sales Recorder
 *
 * Snapshots campaign-attributed line items at the exact moment an order is
 * created — the ONLY moment we can be certain which campaign applied (the
 * discount was just calculated, the campaign is provably live right now).
 *
 * Key design decisions:
 *  - Only items matched by CampaignResolver::findForProduct() are recorded.
 *    Products NOT under any campaign are ignored entirely; they belong to
 *    regular WooCommerce sales and must never appear in campaign reports.
 *  - Revenue saved = item->get_total() = line total AFTER campaign discount,
 *    excluding tax. This is what the customer actually paid for that item.
 *  - The UNIQUE(order_id, product_id, campaign_id) key makes every record()
 *    call idempotent, so duplicate hook fires cause no double-counting.
 *  - order_status is kept in sync via onStatusChanged() so aggregate
 *    queries in CampaignSalesRepository can filter by PAID_STATUSES.
 *
 * Hooks registered:
 *  - woocommerce_checkout_order_processed            (classic checkout)
 *  - woocommerce_store_api_checkout_order_processed  (block checkout)
 *  - woocommerce_new_order                           (admin / programmatic)
 *  - woocommerce_order_status_changed                (status sync + safety net)
 *
 * @package Msi\Campaignchi\Analytics\Services
 */
class CampaignSalesRecorder
{
    public function __construct(
        private CampaignResolver $resolver,
        private CampaignSalesRepository $sales
    ) {}

    // =========================================================
    // HOOK REGISTRATION
    // =========================================================

    public function register(): void
    {
        // Classic checkout and programmatic order creation pass an ID.
        add_action('woocommerce_checkout_order_processed', [$this, 'recordById'], 20, 1);
        add_action('woocommerce_new_order', [$this, 'recordById'], 20, 1);

        // Block-based Store API checkout passes the order object directly.
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'recordByObject'], 20, 1);

        // Status changes: keep order_status in sync AND act as a safety net
        // for admin orders whose items might have been added post-creation.
        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 20, 3);
    }

    // =========================================================
    // PUBLIC HOOK CALLBACKS
    // =========================================================

    /**
     * Hook variant that receives an order ID (checkout_order_processed / new_order).
     *
     * @param int|string $orderId
     */
    public function recordById($orderId): void
    {
        $order = wc_get_order((int) $orderId);

        if ($order instanceof \WC_Order) {
            $this->snapshot($order);
        }
    }

    /**
     * Hook variant that receives the WC_Order object directly (Store API block checkout).
     *
     * @param mixed $order
     */
    public function recordByObject($order): void
    {
        if ($order instanceof \WC_Order) {
            $this->snapshot($order);
        }
    }

    /**
     * Sync order_status on every WooCommerce status transition.
     *
     * Also acts as a safety-net snapshot on the FIRST transition to a paid
     * status: catches admin-created orders whose items were added after the
     * woocommerce_new_order hook already fired (so snapshot() was a no-op).
     *
     * @param int|string $orderId
     * @param string     $from    Previous WooCommerce status (without "wc-" prefix)
     * @param string     $to      New WooCommerce status (without "wc-" prefix)
     */
    public function onStatusChanged($orderId, $from, $to): void
    {
        $orderId = (int) $orderId;
        $to      = (string) $to;

        // Always keep cached status current so PAID_STATUSES filter is accurate.
        $this->sales->updateOrderStatus($orderId, $to);

        // Safety-net: if this is the first paid transition and we have no rows yet,
        // attempt a snapshot now (items may have been added after new_order fired).
        $becamePaid = in_array($to, CampaignSalesRepository::PAID_STATUSES, true);

        if ($becamePaid && !$this->sales->hasOrder($orderId)) {
            $order = wc_get_order($orderId);
            if ($order instanceof \WC_Order) {
                $this->snapshot($order);
            }
        }
    }

    // =========================================================
    // CORE SNAPSHOT LOGIC
    // =========================================================

    /**
     * Walk every line item of the order.
     * For each item covered by a live campaign, persist one snapshot row.
     * Items NOT under any campaign are silently skipped — they are regular
     * WooCommerce sales and must NOT appear in campaign reports.
     *
     * Idempotent: re-fires of any hook for the same order are harmless because
     * CampaignSalesRepository::record() uses ON DUPLICATE KEY UPDATE.
     *
     * @param \WC_Order $order The fully-created WooCommerce order.
     */
    private function snapshot(\WC_Order $order): void
    {
        $orderId       = $order->get_id();
        $status        = $order->get_status();
        $soldAt        = $this->localSoldAt($order);
        $customerName  = trim(
            $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
        );
        $customerEmail = $order->get_billing_email();

        foreach ($order->get_items() as $item) {
            // Only process product line items (skip shipping, fees, etc.).
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            // Campaigns target parent products; resolve variation → parent.
            $productId = $product->get_parent_id() ?: $product->get_id();

            // Ask the live campaign map: is this product under a campaign RIGHT NOW?
            // If not, skip — this item belongs to regular WooCommerce revenue.
            $campaign = $this->resolver->findForProduct($productId);
            if (!$campaign) {
                continue;
            }

            // Revenue = line total AFTER campaign discount, excl. tax.
            // This is exactly what the customer paid for this campaign item.
            $this->sales->record(
                orderId:       $orderId,
                campaignId:    (int) $campaign['id'],
                productId:     (int) $productId,
                qty:           (int) $item->get_quantity(),
                revenue:       (float) $item->get_total(),
                customerName:  $customerName,
                customerEmail: $customerEmail,
                orderStatus:   $status,
                soldAt:        $soldAt
            );
        }
    }

    // =========================================================
    // HELPERS
    // =========================================================

    /**
     * Return the order's creation time in the SITE timezone (not UTC).
     *
     * Reports filter by sold_at using site-local Jalali date ranges, so the
     * stored timestamp must be in the same timezone as those ranges.
     *
     * @param \WC_Order $order
     * @return string Y-m-d H:i:s in site-local timezone
     */
    private function localSoldAt(\WC_Order $order): string
    {
        $created = $order->get_date_created();

        if (!$created) {
            // Fallback: current_time('mysql') is always site-local.
            return current_time('mysql');
        }

        // WC_DateTime extends DateTime — set the timezone before formatting.
        $created->setTimezone(wp_timezone());

        return $created->format('Y-m-d H:i:s');
    }
}