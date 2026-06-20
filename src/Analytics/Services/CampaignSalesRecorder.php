<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Services;

use Msi\Campaignchi\Analytics\Repositories\CampaignSalesRepository;
use Msi\Campaignchi\Campaign\Pricing\CampaignResolver;

/**
 * Campaign Sales Recorder
 *
 * Snapshots, at the exact moment an order is created, which of its line
 * items were under a LIVE campaign — the only moment that information is
 * provably correct (the discount was just applied, the campaign is live
 * right now). The snapshot is persisted to wp_cmc_campaign_sales and then
 * kept status-synced as the order moves through its lifecycle.
 *
 * This is what makes the Reports section accurate: it no longer guesses
 * "was this product under a campaign back then?" — it simply reads facts
 * that were recorded as they happened.
 *
 * Hooks:
 *  - woocommerce_checkout_order_processed            (classic checkout)
 *  - woocommerce_store_api_checkout_order_processed  (block checkout)
 *  - woocommerce_new_order                           (admin / programmatic)
 *  - woocommerce_order_status_changed                (status sync + safety-net snapshot)
 *
 * @package Msi\Campaignchi\Analytics\Services
 */
class CampaignSalesRecorder
{
    public function __construct(
        private CampaignResolver $resolver,
        private CampaignSalesRepository $sales
    ) {}

    public function register(): void
    {
        add_action('woocommerce_checkout_order_processed', [$this, 'recordById'], 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'recordByObject'], 20, 1);
        add_action('woocommerce_new_order', [$this, 'recordById'], 20, 1);
        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 20, 3);
    }

    /** Hook signature that passes an order id (checkout_order_processed / new_order). */
    public function recordById($orderId): void
    {
        $order = wc_get_order((int) $orderId);

        if ($order instanceof \WC_Order) {
            $this->snapshot($order);
        }
    }

    /** Hook signature that passes the order object (Store API block checkout). */
    public function recordByObject($order): void
    {
        if ($order instanceof \WC_Order) {
            $this->snapshot($order);
        }
    }

    /**
     * Keep snapshot rows' order_status in sync, and as a safety net take a
     * snapshot on the first transition to a paid status if one was never
     * captured (e.g. an admin order whose items were added after creation).
     */
    public function onStatusChanged($orderId, $from, $to): void
    {
        $orderId = (int) $orderId;
        $to      = (string) $to;

        $this->sales->updateOrderStatus($orderId, $to);

        $becamePaid = in_array($to, CampaignSalesRepository::PAID_STATUSES, true);

        if ($becamePaid && !$this->sales->hasOrder($orderId)) {
            $order = wc_get_order($orderId);
            if ($order instanceof \WC_Order) {
                $this->snapshot($order);
            }
        }
    }

    /**
     * Inspect every line item; for each one currently covered by a live
     * campaign, persist a snapshot row. Idempotent at the repository level.
     */
    private function snapshot(\WC_Order $order): void
    {
        $orderId       = $order->get_id();
        $status        = $order->get_status();
        $soldAt        = $this->localSoldAt($order);
        $customerName  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $customerEmail = $order->get_billing_email();

        foreach ($order->get_items() as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            // Use the parent id for variations — campaigns target parents.
            $productId = $product->get_parent_id() ?: $product->get_id();
            $campaign  = $this->resolver->findForProduct($productId);

            if (!$campaign) {
                continue;
            }

            $this->sales->record(
                $orderId,
                (int) $campaign['id'],
                (int) $productId,
                (int) $item->get_quantity(),
                (float) $item->get_total(), // line total after discount, excl. tax — what was actually paid
                $customerName,
                $customerEmail,
                $status,
                $soldAt
            );
        }
    }

    /**
     * The order's creation time, expressed in the SITE timezone so it lines
     * up with the site-local Jalali ranges the Reports UI filters by.
     */
    private function localSoldAt(\WC_Order $order): string
    {
        $created = $order->get_date_created();

        if (!$created) {
            return current_time('mysql'); // site-local fallback
        }

        // WC_DateTime extends DateTime; align it to the site timezone first.
        $created->setTimezone(wp_timezone());

        return $created->format('Y-m-d H:i:s');
    }
}