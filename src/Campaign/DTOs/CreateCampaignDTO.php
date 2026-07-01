<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\DTOs;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CreateCampaignDTO
 *
 * Validated, sanitized data transfer object for campaign creation.
 * Built from raw $_POST — all values are clean when this object exists.
 *
 * @package Msi\Campaignchi\Campaign\DTOs
 */
class CreateCampaignDTO
{
    public function __construct(
        public readonly string  $title,
        public readonly string  $type,
        public readonly float   $discount,
        public readonly string  $discountType,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly ?string $description,
        public readonly string  $selectionMode,   // manual | category | tag | attribute | brand | all
        public readonly array   $productIds,       // for manual mode
        public readonly array   $categoryIds,      // for category mode
        public readonly array   $tagIds,           // for tag mode
        public readonly array   $attributeRules,   // [{taxonomy, term_id}]
        public readonly array   $brandIds,         // for brand mode

        public readonly string  $status,           // draft | active | scheduled
    ) {}

    // -------------------------------------------------------
    // Factory — build from sanitized POST data
    // -------------------------------------------------------

    /**
     * Build DTO from raw $_POST array.
     * Throws \InvalidArgumentException on validation failure.
     *
     * @param array $post Raw $_POST
     * @throws \InvalidArgumentException
     */
    public static function fromPost(array $post): self
    {
        // --- Required fields ---
        $post = wp_unslash($post);
        $title = sanitize_text_field($post['title'] ?? '');
        if (empty($title)) {
            throw new \InvalidArgumentException(__('عنوان کمپین الزامی است.', 'campaignchi'));
        }

        $type = sanitize_key($post['type'] ?? 'flash_sale');
        if (!in_array($type, ['flash_sale', 'amazing_offer'], true)) {
            $type = 'flash_sale';
        }

        $discount = (float) ($post['discount'] ?? 0);
        if ($discount <= 0) {
            throw new \InvalidArgumentException(__('مقدار تخفیف باید بزرگتر از صفر باشد.', 'campaignchi'));
        }

        $discountType = sanitize_key($post['discount_type'] ?? 'percent');
        if (!in_array($discountType, ['percent', 'fixed'], true)) {
            $discountType = 'percent';
        }

        // Percent validation
        if ($discountType === 'percent' && $discount > 100) {
            throw new \InvalidArgumentException(__('درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.', 'campaignchi'));
        }

        $brandIds = array_map(
            'absint',
            (array) json_decode(sanitize_text_field($post['brand_ids'] ?? '[]'), true)
        );

        // --- Optional dates (full format + hour/minute range validation) ---
        $startsAt = !empty($post['starts_at'])
            ? self::validateDateTime(sanitize_text_field($post['starts_at']), __('تاریخ شروع', 'campaignchi'))
            : null;

        $endsAt = !empty($post['ends_at'])
            ? self::validateDateTime(sanitize_text_field($post['ends_at']), __('تاریخ پایان', 'campaignchi'))
            : null;

        // --- Business rule: end date must be after start date ---
        if ($startsAt !== null && $endsAt !== null) {
            $startsTs = strtotime($startsAt);
            $endsTs   = strtotime($endsAt);

            if ($endsTs <= $startsTs) {
                throw new \InvalidArgumentException(
                    __('تاریخ و ساعت پایان کمپین باید بعد از تاریخ و ساعت شروع باشد.', 'campaignchi')
                );
            }
        }

        $description = !empty($post['description'])
            ? sanitize_textarea_field($post['description'])
            : null;

        // --- Selection mode ---
        $selectionMode = sanitize_key($post['selection_mode'] ?? 'manual');
        if (!in_array($selectionMode, ['manual', 'category', 'tag', 'attribute', 'brand', 'all'], true)) {
            $selectionMode = 'manual';
        }

        // --- Products / categories / tags ---
        $productIds = array_map(
            'absint',
            (array) json_decode(sanitize_text_field($post['product_ids'] ?? '[]'), true)
        );

        $categoryIds = array_map(
            'absint',
            (array) json_decode(sanitize_text_field($post['category_ids'] ?? '[]'), true)
        );

        $tagIds = array_map(
            'absint',
            (array) json_decode(sanitize_text_field($post['tag_ids'] ?? '[]'), true)
        );

        // Attribute rules: [{taxonomy: "pa_color", term_id: 5}]
        $rawAttr        = json_decode(sanitize_text_field($post['attribute_rules'] ?? '[]'), true);
        $attributeRules = [];
        if (is_array($rawAttr)) {
            foreach ($rawAttr as $rule) {
                if (!empty($rule['taxonomy']) && !empty($rule['term_id'])) {
                    $attributeRules[] = [
                        'taxonomy' => sanitize_key($rule['taxonomy']),
                        'term_id'  => absint($rule['term_id']),
                    ];
                }
            }
        }

        // --- Status ---
        $status = sanitize_key($post['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'active', 'scheduled'], true)) {
            $status = 'draft';
        }

        return new self(
            title: $title,
            type: $type,
            discount: $discount,
            discountType: $discountType,
            startsAt: $startsAt,
            endsAt: $endsAt,
            description: $description,
            selectionMode: $selectionMode,
            productIds: array_filter($productIds),
            categoryIds: array_filter($categoryIds),
            tagIds: array_filter($tagIds),
            attributeRules: $attributeRules,
            brandIds: array_filter($brandIds),
            status: $status,
        );
    }

    // -------------------------------------------------------
    // VALIDATION HELPERS
    // -------------------------------------------------------

    /**
     * Validate and normalize a date/time string coming from the picker.
     *
     * Accepted formats:
     *   - 'YYYY-MM-DDTHH:MM'        (what datepicker.js sends)
     *   - 'YYYY-MM-DD HH:MM'
     *   - 'YYYY-MM-DD HH:MM:SS'
     *
     * Validation rules (same as a real clock):
     *   - date must be valid (via checkdate)
     *   - hour must be between 00 and 23
     *   - minute must be between 00 and 59
     *
     * Output is always normalized to standard MySQL DATETIME format:
     * 'YYYY-MM-DD HH:MM:SS'.
     *
     * @param string $value
     * @param string $fieldLabel  Used in error messages (e.g. "start date")
     * @return string
     * @throws \InvalidArgumentException
     */
    private static function validateDateTime(string $value, string $fieldLabel): string
    {
        $value = trim($value);

        // 'YYYY-MM-DD[ T]HH:MM[:SS]'
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::(\d{2}))?$/';

        if (!preg_match($pattern, $value, $m)) {
            throw new \InvalidArgumentException(
                sprintf(__('فرمت «%s» نامعتبر است.', 'campaignchi'), $fieldLabel)
            );
        }

        $year   = (int) $m[1];
        $month  = (int) $m[2];
        $day    = (int) $m[3];
        $hour   = (int) $m[4];
        $minute = (int) $m[5];
        $second = isset($m[6]) ? (int) $m[6] : 0;

        // --- Date must be valid (e.g. reject Feb 30 or Feb 29 in a non-leap year) ---
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException(
                sprintf(__('تاریخ «%s» نامعتبر است.', 'campaignchi'), $fieldLabel)
            );
        }

        // --- Hour: 00 to 23, same as a real clock ---
        if ($hour < 0 || $hour > 23) {
            throw new \InvalidArgumentException(
                sprintf(__('ساعت «%s» باید بین ۰۰ تا ۲۳ باشد.', 'campaignchi'), $fieldLabel)
            );
        }

        // --- Minute: 00 to 59, same as a real clock ---
        if ($minute < 0 || $minute > 59) {
            throw new \InvalidArgumentException(
                sprintf(__('دقیقه «%s» باید بین ۰۰ تا ۵۹ باشد.', 'campaignchi'), $fieldLabel)
            );
        }

        // --- Second (if present) ---
        if ($second < 0 || $second > 59) {
            $second = 0;
        }

        // Normalized output for storage in the DATETIME column
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }
}