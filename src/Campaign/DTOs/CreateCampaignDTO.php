<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\DTOs;

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
        public readonly string  $selectionMode,   // manual | category | tag | attribute | all
        public readonly array   $productIds,       // for manual mode
        public readonly array   $categoryIds,      // for category mode
        public readonly array   $tagIds,           // for tag mode
        public readonly array   $attributeRules,   // [{taxonomy, term_id}]
        public readonly array   $brandIds,        // ← اضافه کن بعد از $attributeRules

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

        // --- Optional dates ---
        $startsAt = !empty($post['starts_at'])
            ? sanitize_text_field($post['starts_at'])
            : null;

        $endsAt = !empty($post['ends_at'])
            ? sanitize_text_field($post['ends_at'])
            : null;

        $description = !empty($post['description'])
            ? sanitize_textarea_field($post['description'])
            : null;

        // --- Selection mode ---
        $selectionMode = sanitize_key($post['selection_mode'] ?? 'manual');
        if (!in_array($selectionMode, ['manual', 'category', 'tag', 'attribute', 'all'], true)) {
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
}
