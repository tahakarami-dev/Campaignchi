<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Analytics\Services;

use Msi\Campaignchi\Admin\Pages\SettingsPage;
use Msi\Campaignchi\Analytics\Repositories\AnalyticsRepository;
use Msi\Campaignchi\Campaign\Models\Campaign;
use Msi\Campaignchi\Campaign\Pricing\CampaignProductResolver;
use Msi\Campaignchi\Campaign\Pricing\CampaignResolver;
use Msi\Campaignchi\Campaign\Repositories\CampaignRepository;
use Msi\Campaignchi\Helpers\JalaliHelper;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analytics Service
 *
 * Single source of truth for the dashboard. Every number this service
 * produces is computed only from products that were under a campaign at
 * the moment being examined (not just "now") — both in terms of product
 * selection (category/brand/...) and the campaign's own date range.
 *
 * Important timezone note:
 * Throughout this class, "now" is always computed with time() (real UTC),
 * because $order->get_date_created()->getTimestamp() is always real UTC,
 * and campaign created_at/updated_at are also written by CampaignRepository
 * as GMT (current_time('mysql', true)) — so strtotime() on them also
 * returns real UTC. Using current_time('timestamp') here would be wrong,
 * since that value is "now + the site's timezone offset".
 *
 * Campaign starts_at/ends_at follow a different rule: these are "naive"
 * values the admin entered via the Jalali date picker (site-local time,
 * no GMT), and must always be compared against current_time('mysql')
 * (not GMT) — the same way CampaignRepository::getLiveCampaigns() does.
 *
 * @package Msi\Campaignchi\Analytics\Services
 */
class AnalyticsService
{
    /** Versioned cache-key prefix for per-day campaign data. Bumped to v2 when the per-campaign breakdown was added. */
    private const DAILY_CACHE_PREFIX = 'cmc_daily_campaign_data_v2_';

    /** Order statuses that count as a finalized sale */
    private const ORDER_STATUSES = ['processing', 'completed'];

    /** Transient key for the cached, priority-sorted list of non-draft campaigns + resolved product IDs */
    private const CAMPAIGN_CANDIDATES_CACHE_KEY = 'cmc_campaign_candidates_v1';

    /** How long the campaign-candidates cache may live between active invalidations */
    private const CAMPAIGN_CANDIDATES_CACHE_TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * Safety-net TTL for "today"'s cached order/revenue/product data.
     * Real-time freshness comes from active invalidation via
     * flushTodayCache() (hooked to WooCommerce order status changes) —
     * this short TTL only protects against an invalidation hook being
     * missed for some unusual integration that bypasses normal WC hooks.
     */
    private const TODAY_CACHE_TTL = MINUTE_IN_SECONDS;

    /** @var array<int, array{campaign: Campaign, product_ids: array}>|null */
    private ?array $campaignCandidates = null;

    /**
     * Resolve the TTL (seconds) for today's cached analytics.
     *
     * Reads the admin setting (Settings → Performance → analytics_cache_ttl)
     * and clamps it, falling back to the TODAY_CACHE_TTL constant when the
     * settings layer is unavailable.
     */
    private function todayCacheTtl(): int
    {
        if (!class_exists(SettingsPage::class)) {
            return self::TODAY_CACHE_TTL;
        }

        $configured = (int) (SettingsPage::getPerformance()['analytics_cache_ttl'] ?? self::TODAY_CACHE_TTL);

        return max(10, min($configured, 600));
    }

    /**
     * Resolve the TTL (seconds) for the campaign-candidates cache.
     *
     * Reads the admin setting (Settings → Performance → candidates_cache_ttl)
     * and clamps it, falling back to the CAMPAIGN_CANDIDATES_CACHE_TTL constant.
     */
    private function candidatesCacheTtl(): int
    {
        if (!class_exists(SettingsPage::class)) {
            return self::CAMPAIGN_CANDIDATES_CACHE_TTL;
        }

        $configured = (int) (SettingsPage::getPerformance()['candidates_cache_ttl'] ?? self::CAMPAIGN_CANDIDATES_CACHE_TTL);

        return max(60, min($configured, 3600));
    }

    public function __construct(
        private AnalyticsRepository $stats,
        private CampaignRepository $campaigns,
        private CampaignResolver $resolver,
        private CampaignProductResolver $productResolver
    ) {}

    // =========================================================
    // 1) STAT CARDS — top row of the dashboard
    // =========================================================

    /**
     * @return array{
     *   active_campaigns: array{value:string, direction:string, change_label:string},
     *   sales_today:      array{value:string, direction:string, change_label:string},
     *   conversion_rate:  array{value:string, direction:string, change_label:string},
     *   views_today:      array{value:string, direction:string, change_label:string},
     * }
     */
    public function getStatCards(): array
    {
        $todayRange     = $this->dayRange(0);
        $yesterdayRange = $this->dayRange(1);

        $todayOrders     = $this->getCampaignOrderStats($todayRange);
        $yesterdayOrders = $this->getCampaignOrderStats($yesterdayRange);

        $todayImpressions     = $this->stats->getTotalImpressions($todayRange['date']);
        $yesterdayImpressions = $this->stats->getTotalImpressions($yesterdayRange['date']);

        $todayConversion     = $this->conversionRate($todayOrders['orders'], $todayImpressions);
        $yesterdayConversion = $this->conversionRate($yesterdayOrders['orders'], $yesterdayImpressions);

        return [
            'active_campaigns' => $this->activeCampaignsStat(),
            'sales_today'      => $this->buildStat($todayOrders['revenue'], $yesterdayOrders['revenue'], 'number'),
            'conversion_rate'  => $this->buildStat($todayConversion, $yesterdayConversion, 'percent'),
            'views_today'      => $this->buildStat((float) $todayImpressions, (float) $yesterdayImpressions, 'number'),
        ];
    }

    private function activeCampaignsStat(): array
    {
        $liveCount = count($this->campaigns->getLiveCampaigns());

        // ⚠️ BUG FIX: created_at is now stored in GMT (see
        // CampaignRepository::create()), so the comparison threshold must
        // also be GMT. gmdate() on a relative strtotime() offset keeps both
        // sides of the "created in the last 7 days" query in the same
        // timezone convention, regardless of the site's UTC offset.
        $weekAgo     = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
        $newThisWeek = $this->campaigns->countCreatedSince($weekAgo);

        $direction = $newThisWeek > 0 ? 'up' : 'flat';
        $label = $newThisWeek > 0
            ? sprintf(
                __('%s کمپین جدید این هفته', 'campaignchi'),
                JalaliHelper::toPersianNums((string) $newThisWeek)
            )
            : __('بدون کمپین جدید این هفته', 'campaignchi');

        return [
            'value'        => JalaliHelper::toPersianNums((string) $liveCount),
            'direction'    => $direction,
            'change_label' => $label,
        ];
    }

    // =========================================================
    // 2) WEEKLY CHART — campaign sales chart, current week (Saturday to Friday)
    // =========================================================

    /**
     * @return array<int, array{label:string, value:float, percent:int, is_today:bool, value_label:string}>
     */
    public function getWeeklyChart(): array
    {
        $tz  = wp_timezone();
        $now = new \DateTime('now', $tz);

        // The Persian calendar week starts on Saturday.
        // PHP's date('w') returns 0=Sunday..6=Saturday, so "days elapsed
        // since last Saturday" = (current_dow + 1) % 7. Walking back that
        // many days from "today" always lands on the Saturday that opens
        // the current Persian week.
        $daysSinceSaturday = ((int) $now->format('w') + 1) % 7;
        $saturday          = (clone $now)->modify("-{$daysSinceSaturday} days");

        $days = [];

        // Build Saturday → Friday in exactly that order. The admin panel is
        // RTL, so the FIRST item pushed into this array renders on the
        // RIGHT edge of the chart and the LAST item on the LEFT — meaning
        // Saturday ends up on the right and Friday on the left, matching
        // the requested Persian-week layout.
        for ($i = 0; $i < 7; $i++) {
            $date  = (clone $saturday)->modify("+{$i} days");
            $range = $this->dayRangeForDate($date);
            $stats = $this->getCampaignOrderStats($range);

            $days[] = [
                'date'  => $range['date'],
                'label' => $this->weekdayLabel($range['date']),
                'value' => $stats['revenue'],
            ];
        }

        $max = max(array_column($days, 'value'));
        $max = $max > 0 ? $max : 1;

        $today = $now->format('Y-m-d');

        $bars = [];
        foreach ($days as $d) {
            $bars[] = [
                'label'       => $d['label'],
                'value'       => $d['value'],
                'percent'     => (int) round(($d['value'] / $max) * 100),
                'is_today'    => $d['date'] === $today,
                'value_label' => $this->abbreviateNumber($d['value']),
            ];
        }

        return $bars;
    }

    // =========================================================
    // 3) ACTIVE CAMPAIGNS WIDGET
    // =========================================================

    /**
     * @return array<int, array{
     *   campaign: Campaign,
     *   meta: string,
     *   stock_percent: ?int,
     *   is_urgent: bool
     * }>
     */
    public function getActiveCampaignsWidget(int $limit = 2): array
    {
        $campaigns = array_slice($this->campaigns->getLiveCampaigns(), 0, $limit);
        $result    = [];

        foreach ($campaigns as $campaign) {
            $productIds = $this->productResolver->resolve($campaign);
            $stock      = $this->calculateStock($productIds);
            $timeLabel  = $this->timeRemainingLabel($campaign);

            $productLabel = $productIds === [CampaignProductResolver::ALL_PRODUCTS]
                ? __('همه محصولات', 'campaignchi')
                : sprintf(
                    __('%s محصول', 'campaignchi'),
                    JalaliHelper::toPersianNums((string) count($productIds))
                );

            $result[] = [
                'campaign'      => $campaign,
                'meta'          => $productLabel . ' · ' . $timeLabel,
                'stock_percent' => $stock,
                'is_urgent'     => $this->isUrgent($campaign),
            ];
        }

        return $result;
    }

    /**
     * Remaining stock percentage for a campaign's products.
     * Only computed for products with stock management enabled.
     */
    private function calculateStock(array $productIds): ?int
    {
        if ($productIds === [CampaignProductResolver::ALL_PRODUCTS] || empty($productIds)) {
            return null;
        }

        $totalStock = 0;
        $managed    = 0;

        foreach ($productIds as $pid) {
            $product = wc_get_product($pid);
            if (!$product || !$product->managing_stock()) {
                continue;
            }
            $totalStock += max(0, (int) $product->get_stock_quantity());
            $managed++;
        }

        if ($managed === 0) {
            return null;
        }

        $maxPossible = $totalStock > 0 ? $totalStock : 1;

        return (int) round(min(100, ($totalStock / $maxPossible) * 100));
    }

    /**
     * Determine whether a campaign is in its "urgent" phase (ending very soon).
     *
     * BUG FIX (timezone): `$campaign->endsAt` is a naive, site-local DATETIME
     * string written by the admin's date-picker — it represents the site's local
     * time, NOT UTC. PHP's `strtotime()` with no explicit timezone parses it as
     * if it were in the PHP default timezone (typically UTC on most hosts).
     *
     * On a site with timezone UTC+3:30 (Asia/Tehran):
     *   - endsAt stored: "2024-01-01 10:00:00" (Tehran local = 06:30 UTC)
     *   - strtotime("2024-01-01 10:00:00") → Unix ts for 2024-01-01 10:00 UTC
     *   - time()                             → actual UTC now
     *   - diff = 10:00 UTC-stored minus real-UTC-now = inflated by 3.5 hours
     *
     * Fix: diff against `current_time('timestamp')` — WordPress's "site-local
     * Unix timestamp" — which applies the same UTC-offset convention that
     * `strtotime()` uses on the naive datetime string.
     *
     * @param Campaign $campaign
     * @return bool True when the campaign ends within 3 hours from now.
     */
    private function isUrgent(Campaign $campaign): bool
    {
        if ($campaign->type !== 'flash_sale' || empty($campaign->endsAt)) {
            return false;
        }

        // strtotime() on a naive site-local string + current_time('timestamp')
        // both use the same "site-local Unix timestamp" convention.
        $endTs = strtotime($campaign->endsAt);
        $now   = current_time('timestamp');

        return ($endTs - $now) <= (3 * HOUR_IN_SECONDS) && $endTs > $now;
    }

    /**
     * Build a human-readable "time remaining" label for a campaign.
     *
     * BUG FIX (timezone): Same root cause as isUrgent() above.
     * `$campaign->endsAt` is a naive, site-local DATETIME string.
     * Using `time()` (true UTC) for the diff inflates the result by the
     * site's UTC offset — e.g. a campaign expiring in 1 minute appears to
     * have 3 hours 31 minutes left on a UTC+3:30 site.
     *
     * Fix: use `current_time('timestamp')` so both sides of the subtraction
     * use the same "naive site-local" timezone convention.
     *
     * @param Campaign $campaign
     * @return string Localised label like "۵ دقیقه مانده" or "بدون محدودیت زمانی".
     */
    private function timeRemainingLabel(Campaign $campaign): string
    {
        if ($campaign->type === 'amazing_offer' || empty($campaign->endsAt)) {
            return __('بدون محدودیت زمانی', 'campaignchi');
        }

        // strtotime() on a naive, site-local DATETIME uses the PHP default
        // timezone (UTC on most hosts), producing a "site-local Unix timestamp".
        // current_time('timestamp') returns the same convention: UTC epoch
        // PLUS the site's UTC offset.  The diff is therefore correct.
        $endTs = strtotime($campaign->endsAt);
        $now   = current_time('timestamp');
        $diff  = $endTs - $now;

        if ($diff <= 0) {
            return __('در حال پایان', 'campaignchi');
        }

        $hours = (int) floor($diff / HOUR_IN_SECONDS);

        if ($hours < 1) {
            $minutes = (int) floor($diff / MINUTE_IN_SECONDS);
            return sprintf(__('%s دقیقه مانده', 'campaignchi'), JalaliHelper::toPersianNums((string) $minutes));
        }

        if ($hours < 24) {
            return sprintf(__('%s ساعت مانده', 'campaignchi'), JalaliHelper::toPersianNums((string) $hours));
        }

        $days = (int) floor($hours / 24);
        return sprintf(__('%s روز مانده', 'campaignchi'), JalaliHelper::toPersianNums((string) $days));
    }

    // =========================================================
    // 4) TOP CAMPAIGN PRODUCTS — today's best-selling campaign products
    // =========================================================

    /**
     * @return array<int, array{name:string, qty:string, price:string, regular_price:?string, campaign_title:string}>
     */
    public function getTopProducts(int $limit = 3): array
    {
        $data = $this->getDailyCampaignData($this->dayRange(0));
        $top  = array_slice($data['products'], 0, $limit);

        $result = [];
        foreach ($top as $row) {
            $product = wc_get_product($row['id']);
            if (!$product) {
                continue;
            }

            $regular = (float) $product->get_regular_price();
            $current = (float) $product->get_price();

            $result[] = [
                'name'           => $product->get_name(),
                'qty'            => JalaliHelper::toPersianNums((string) $row['qty']),
                'price'          => wc_price($current),
                'regular_price'  => $regular > $current ? wc_price($regular) : null,
                'campaign_title' => $row['campaign_title'],
            ];
        }

        return $result;
    }

    // =========================================================
    // 5) RECENT ACTIVITY FEED
    // =========================================================

    /**
     * @return array<int, array{icon_class:string, ti:string, text:string, time_label:string}>
     */
    public function getRecentActivity(int $limit = 4): array
    {
        $activities = [];

        foreach ($this->campaigns->getRecentlyChanged(5) as $campaign) {
            $activities[] = [
                'icon_class' => $this->activityIconClass($campaign->status),
                'ti'         => $this->activityIcon($campaign->status),
                'text'       => $this->campaignActivityText($campaign),
                'time'       => strtotime($campaign->updatedAt),
            ];
        }

        $dailyData = $this->getDailyCampaignData($this->dayRange(0));

        // 5 most recent orders (list is already sorted by date descending)
        foreach (array_slice($dailyData['order_activities'], 0, 5) as $order) {
            $activities[] = [
                'icon_class' => 'cmc-activity-item__icon--success',
                'ti'         => 'ti-check',
                'text'       => sprintf(
                    __('سفارش #%s شامل محصولات کمپین ثبت شد', 'campaignchi'),
                    JalaliHelper::toPersianNums((string) $order['order_id'])
                ),
                'time' => $order['time'],
            ];
        }

        // Final order: always newest to oldest (by real timestamp)
        usort($activities, fn($a, $b) => $b['time'] <=> $a['time']);
        $activities = array_slice($activities, 0, $limit);

        // ⚠️ BUG FIX (root cause of "recent activity shows way too late"):
        // campaign->updatedAt is now ALWAYS stored in GMT by
        // CampaignRepository::create()/update()/updateStatus(), so
        // strtotime() on it returns a true UTC timestamp — exactly like
        // WooCommerce's $order->get_date_created()->getTimestamp(). That
        // means time() (true UTC "now") is the correct reference here.
        // Previously created_at/updated_at relied on MySQL's own
        // CURRENT_TIMESTAMP, whose timezone is hosting-dependent — mixing
        // that ambiguous value with time() is what caused the elapsed time
        // to be off by the site's UTC offset.
        $now = time();

        foreach ($activities as &$activity) {
            $activity['time_label'] = sprintf(
                __('%s پیش', 'campaignchi'),
                human_time_diff($activity['time'], $now)
            );
        }

        return $activities;
    }

    private function activityIconClass(string $status): string
    {
        return match ($status) {
            'active'    => 'cmc-activity-item__icon--flash',
            'scheduled' => 'cmc-activity-item__icon--info',
            'ended'     => 'cmc-activity-item__icon--warning',
            default     => 'cmc-activity-item__icon--primary',
        };
    }

    private function activityIcon(string $status): string
    {
        return match ($status) {
            'active'    => 'ti-bolt',
            'scheduled' => 'ti-clock',
            'ended'     => 'ti-flag',
            default     => 'ti-edit',
        };
    }

    private function campaignActivityText(Campaign $campaign): string
    {
        return match ($campaign->status) {
            'active'    => sprintf(__('کمپین «%s» فعال شد', 'campaignchi'), $campaign->title),
            'scheduled' => sprintf(__('کمپین «%s» زمان‌بندی شد', 'campaignchi'), $campaign->title),
            'ended'     => sprintf(__('کمپین «%s» به پایان رسید', 'campaignchi'), $campaign->title),
            default     => sprintf(__('کمپین «%s» ذخیره شد', 'campaignchi'), $campaign->title),
        };
    }

    // =========================================================
    // INTERNAL — Order analysis (cached, single-pass)
    // =========================================================

    /**
     * Single-pass computation of every campaign-related order metric for
     * ONE calendar day: revenue, order count, per-product breakdown, and
     * the raw order activity log.
     *
     * ⚠️ PERFORMANCE FIX: this method replaces what used to be TWO separate
     * methods (getCampaignOrderStats's inline loop + a now-removed
     * getTodayCampaignOrdersData()) that each ran their own wc_get_orders()
     * query and looped over every order item for the SAME day — once just
     * for revenue/order count, once for the product/activity breakdown.
     * Merging them into one query + one loop, cached under a single
     * transient, roughly halves the DB/CPU cost of a cold dashboard load.
     *
     * @return array{
     *   revenue: float,
     *   orders: int,
     *   products: array<int, array{id:int, qty:int, campaign_title:string}>,
     *   order_activities: array<int, array{order_id:int, time:int}>
     * }
     */
    private function getDailyCampaignData(array $range): array
    {
        $cacheKey = self::DAILY_CACHE_PREFIX . $range['date'];

        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $orders = wc_get_orders([
            'status'       => self::ORDER_STATUSES,
            'date_created' => $range['start'] . '...' . $range['end'],
            'orderby'      => 'date',
            'order'        => 'DESC',
            'limit'        => -1,
        ]);

        $candidates = $this->getCampaignCandidates();

        $revenue         = 0.0;
        $orderCount      = 0;
        $products        = [];
        $campaigns       = [];
        $orderActivities = [];

        foreach ($orders as $order) {
            $hasCampaignItem = false;
            $orderId         = $order->get_id();

            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) {
                    continue;
                }

                $productId = $product->get_parent_id() ?: $product->get_id();
                $campaign  = $this->findCampaignForProductOnDate($productId, $range['date'], $candidates);

                if (!$campaign) {
                    continue;
                }

                $hasCampaignItem = true;
                $itemTotal       = (float) $item->get_total();
                $itemQty         = (int) $item->get_quantity();
                $revenue        += $itemTotal;

                // Per-product accumulation (existing behavior).
                if (!isset($products[$productId])) {
                    $products[$productId] = [
                        'id'             => $productId,
                        'qty'            => 0,
                        'campaign_title' => $campaign->title,
                    ];
                }
                $products[$productId]['qty'] += $itemQty;

                // Per-campaign accumulation (NEW) — same matched campaign,
                // same loop, no extra query. Orders are de-duplicated per
                // campaign via the temporary `_orders` set, finalized below.
                $cid = $campaign->id;
                if (!isset($campaigns[$cid])) {
                    $campaigns[$cid] = [
                        'id'      => $cid,
                        'title'   => $campaign->title,
                        'type'    => $campaign->type,
                        'revenue' => 0.0,
                        'qty'     => 0,
                        '_orders' => [],
                    ];
                }
                $campaigns[$cid]['revenue']        += $itemTotal;
                $campaigns[$cid]['qty']            += $itemQty;
                $campaigns[$cid]['_orders'][$orderId] = true;
            }

            if ($hasCampaignItem) {
                $orderCount++;
                $orderActivities[] = [
                    'order_id' => $orderId,
                    'time'     => $order->get_date_created()->getTimestamp(),
                ];
            }
        }

        // Finalize per-campaign order counts (count distinct orders that
        // contained at least one item from that campaign).
        foreach ($campaigns as &$campaignRow) {
            $campaignRow['orders'] = count($campaignRow['_orders']);
            unset($campaignRow['_orders']);
        }
        unset($campaignRow);

        usort($products, fn($a, $b) => $b['qty'] <=> $a['qty']);

        $data = [
            'revenue'          => $revenue,
            'orders'           => $orderCount,
            'products'         => array_values($products),
            'campaigns'        => array_values($campaigns),
            'order_activities' => $orderActivities,
        ];

        $today = (new \DateTime('now', wp_timezone()))->format('Y-m-d');
        $ttl   = $range['date'] === $today ? $this->todayCacheTtl() : HOUR_IN_SECONDS;

        set_transient($cacheKey, $data, $ttl);

        return $data;
    }

    /**
     * Revenue + order count for a single day. Thin wrapper kept for
     * readability at call sites that only need the aggregate numbers
     * (stat cards, weekly chart) — internally backed by the single-pass,
     * cached getDailyCampaignData().
     *
     * @return array{revenue: float, orders: int}
     */
    private function getCampaignOrderStats(array $range): array
    {
        $data = $this->getDailyCampaignData($range);

        return ['revenue' => $data['revenue'], 'orders' => $data['orders']];
    }

    /**
     * Force-refresh today's cached analytics on the next read.
     *
     * ⚠️ PERFORMANCE/FRESHNESS FIX: hooked to WooCommerce order status
     * changes (see AnalyticsServiceProvider::flushTodayCache()) so the
     * dashboard reflects a brand-new sale immediately, instead of waiting
     * up to TODAY_CACHE_TTL seconds — while still avoiding a full
     * order-table scan on every single dashboard page view in between.
     */
    public function flushTodayCache(): void
    {
        $today = (new \DateTime('now', wp_timezone()))->format('Y-m-d');
        delete_transient(self::DAILY_CACHE_PREFIX . $today);
    }

    // =========================================================
    // HISTORICAL CAMPAIGN MATCHING
    // Key rule: every calculation (whether for today or another day of the
    // week) only includes products that were under a campaign on that date.
    // =========================================================

    /**
     * List of all non-draft campaigns with each one's resolved products.
     *
     * In addition to being memoized per-request, the result is also cached
     * in a transient. resolve() runs several taxonomy queries
     * (get_objects_in_term) for category/tag/attribute/brand modes; without
     * this cache, those queries would re-run on every dashboard load (and
     * every per-weekday calculation).
     *
     * @return array<int, array{campaign: Campaign, product_ids: array}>
     */
    private function getCampaignCandidates(): array
    {
        if ($this->campaignCandidates !== null) {
            return $this->campaignCandidates;
        }

        $cached = get_transient(self::CAMPAIGN_CANDIDATES_CACHE_KEY);
        if (is_array($cached)) {
            return $this->campaignCandidates = $cached;
        }

        $candidates = [];

        foreach ($this->campaigns->getNonDraftCampaigns() as $campaign) {
            $candidates[] = [
                'campaign'    => $campaign,
                'product_ids' => $this->productResolver->resolve($campaign),
            ];
        }

        // Priority: flash_sale first, then newest
        usort($candidates, function (array $a, array $b): int {
            $aFlash = $a['campaign']->type === 'flash_sale' ? 1 : 0;
            $bFlash = $b['campaign']->type === 'flash_sale' ? 1 : 0;

            if ($aFlash !== $bFlash) {
                return $bFlash - $aFlash;
            }

            return $b['campaign']->id - $a['campaign']->id;
        });

        set_transient(self::CAMPAIGN_CANDIDATES_CACHE_KEY, $candidates, $this->candidatesCacheTtl());

        return $this->campaignCandidates = $candidates;
    }

    /**
     * Invalidate the cached campaign-candidates list immediately.
     * Hooked (see AnalyticsServiceProvider::boot()) to campaign changes
     * and product taxonomy/save events, since both can change which
     * products a campaign resolves to.
     */
    public static function flushCampaignCandidatesCache(): void
    {
        delete_transient(self::CAMPAIGN_CANDIDATES_CACHE_KEY);
    }

    /**
     * Was this product under a campaign on the given date?
     * Returns the first matching campaign, by getCampaignCandidates() priority.
     *
     * @param array<int, array{campaign: Campaign, product_ids: array}> $candidates
     */
    private function findCampaignForProductOnDate(int $productId, string $date, array $candidates): ?Campaign
    {
        foreach ($candidates as $candidate) {
            $campaign   = $candidate['campaign'];
            $productIds = $candidate['product_ids'];

            $isAllProducts = $productIds === [CampaignProductResolver::ALL_PRODUCTS];

            if (!$isAllProducts && !in_array($productId, $productIds, true)) {
                continue;
            }

            if (!$this->campaignCoversDate($campaign, $date)) {
                continue;
            }

            return $campaign;
        }

        return null;
    }

    /**
     * Was a campaign considered running on the given date (Y-m-d, site timezone)?
     *
     * - flash_sale: checks the [starts_at, ends_at] range (no bound = open).
     *   These values are naive/site-local (from the Jalali date picker), so
     *   a direct string comparison is correct.
     * - amazing_offer: has no explicit start/end date, so it's estimated:
     *     - active    -> covered from the very beginning until now
     *     - ended     -> covered until updated_at (estimated end date)
     *     - scheduled -> from updated_at onward
     */
    private function campaignCoversDate(Campaign $campaign, string $date): bool
    {
        if ($campaign->type === 'flash_sale') {
            $start = $campaign->startsAt ? substr($campaign->startsAt, 0, 10) : null;
            $end   = $campaign->endsAt   ? substr($campaign->endsAt, 0, 10)   : null;

            if ($start !== null && $date < $start) {
                return false;
            }

            if ($end !== null && $date > $end) {
                return false;
            }

            return true;
        }

        // amazing_offer
        // ⚠️ BUG FIX: updated_at is now stored in GMT (see
        // CampaignRepository), while $date is a site-local calendar date
        // (derived from wp_timezone()). get_date_from_gmt() converts the
        // GMT value back to the site's local date before comparing, so
        // the boundary stays correct near local midnight regardless of
        // the site's UTC offset.
        $updatedDate = get_date_from_gmt($campaign->updatedAt, 'Y-m-d');

        return match ($campaign->status) {
            'active'    => true,
            'ended'     => $date <= $updatedDate,
            'scheduled' => $date >= $updatedDate,
            default     => false,
        };
    }

    // =========================================================
    // HELPERS
    // =========================================================

    /**
     * Start/end of a day (in the site's timezone) as Unix timestamps (UTC) —
     * exactly what wc_get_orders expects for date_created.
     *
     * @return array{date:string, start:int, end:int}
     */
    private function dayRange(int $daysAgo): array
    {
        $date = new \DateTime('now', wp_timezone());
        $date->modify("-{$daysAgo} days");

        return $this->dayRangeForDate($date);
    }

    /**
     * Same as dayRange() but for an arbitrary date (past OR future),
     * needed by getWeeklyChart() to build the full Saturday→Friday week
     * even on days where part of the week hasn't happened yet.
     *
     * @return array{date:string, start:int, end:int}
     */
    private function dayRangeForDate(\DateTimeInterface $date): array
    {
        $tz      = wp_timezone();
        $dateStr = $date->format('Y-m-d');

        $start = (new \DateTime($dateStr . ' 00:00:00', $tz))->getTimestamp();
        $end   = (new \DateTime($dateStr . ' 23:59:59', $tz))->getTimestamp();

        return ['date' => $dateStr, 'start' => $start, 'end' => $end];
    }

    private function conversionRate(int $orders, int $impressions): float
    {
        return $impressions > 0 ? ($orders / $impressions) * 100 : 0.0;
    }

    private function buildStat(float $current, float $previous, string $type): array
    {
        $diff    = $current - $previous;
        $percent = $previous > 0 ? ($diff / $previous) * 100 : ($current > 0 ? 100.0 : 0.0);

        $direction = 'flat';
        if ($percent > 0.05) {
            $direction = 'up';
        } elseif ($percent < -0.05) {
            $direction = 'down';
        }

        return [
            'value'        => $this->formatValue($current, $type),
            'direction'    => $direction,
            'change_label' => $this->formatChangeLabel($percent, $direction),
        ];
    }

    private function formatValue(float $value, string $type): string
    {
        if ($type === 'percent') {
            return JalaliHelper::toPersianNums(number_format($value, 1)) . '٪';
        }

        return $this->abbreviateNumber($value);
    }

    private function formatChangeLabel(float $percent, string $direction): string
    {
        $absPercent = JalaliHelper::toPersianNums(number_format(abs($percent), 1));

        if ($direction === 'flat') {
            return __('بدون تغییر نسبت به دیروز', 'campaignchi');
        }

        if ($direction === 'up') {
            return sprintf(__('%s٪ رشد نسبت به دیروز', 'campaignchi'), $absPercent);
        }

        return sprintf(__('%s٪ کاهش نسبت به دیروز', 'campaignchi'), $absPercent);
    }

    private function abbreviateNumber(float $value): string
    {
        if ($value >= 1_000_000) {
            $formatted = rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.');
            return JalaliHelper::toPersianNums($formatted) . 'M';
        }

        if ($value >= 1_000) {
            $formatted = rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.');
            return JalaliHelper::toPersianNums($formatted) . 'K';
        }

        return JalaliHelper::toPersianNums(number_format($value, 0));
    }

    /**
     * Full Persian weekday name for a given Y-m-d date string.
     *
     * ⚠️ BUG FIX: previously returned 2-3 letter abbreviations from a local
     * lookup table. Now delegates to JalaliHelper::weekdayName(), which
     * returns the complete name (شنبه, یکشنبه, دوشنبه, ...) and uses the
     * exact same 0=Sunday..6=Saturday convention as PHP's date('w'), so no
     * extra mapping is needed.
     */
    private function weekdayLabel(string $dateStr): string
    {
        $w = (int) (new \DateTime($dateStr, wp_timezone()))->format('w');

        return JalaliHelper::weekdayName($w);
    }

    // =========================================================
    // RANGE REPORT — REMOVED
    // getRangeReport() has been removed from this class.
    //
    // The Reports page is now powered exclusively by ReportService, which
    // reads from the campaign-sales event log (wp_cmc_campaign_sales) via
    // CampaignSalesRepository. That approach is accurate because it only
    // counts items that were provably sold under a campaign, recorded at
    // checkout time.
    //
    // The old getRangeReport() scanned raw WooCommerce orders and tried to
    // retroactively determine whether a product was under a campaign on a
    // past date — a fundamentally unreliable approach that could include
    // non-campaign items and was inconsistent with the event-log figures.
    //
    // Do NOT add range-report logic back here. See ReportService.php.
    // =========================================================

    // NOTE: the following private helpers (datesInRange, shortJalaliLabel,
    // formatToman, formatPercent) were used exclusively by getRangeReport()
    // and have been removed together with it to keep this class clean.
    // Equivalent helpers exist in ReportService.php.

}