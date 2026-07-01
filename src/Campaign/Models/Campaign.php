<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Campaign\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign Model
 *
 * Represents a single campaign record from the database.
 * Pure data object — no DB logic here.
 *
 * @package Msi\Campaignchi\Campaign\Models
 */
class Campaign
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $title,
        public readonly string  $status,          // draft | active | scheduled | ended
        public readonly string  $type,            // flash_sale | amazing_offer
        public readonly float   $discount,
        public readonly string  $discountType,    // percent | fixed
        public readonly string  $selectionMode,   // manual | category | tag | attribute | brand | all
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly ?string $description,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    // -------------------------------------------------------
    // Factories
    // -------------------------------------------------------

    /**
     * Build a Campaign from a raw DB row (stdClass or array).
     *
     * @param object|array $row
     */
    public static function fromRow(object|array $row): self
    {
        if (is_array($row)) {
            $row = (object) $row;
        }

        return new self(
            id            : (int)    $row->id,
            title         : (string) $row->title,
            status        : (string) $row->status,
            type          : (string) $row->type,
            discount      : (float)  $row->discount,
            discountType  : (string) $row->discount_type,
            selectionMode : (string) ($row->selection_mode ?? 'manual'),
            startsAt      : $row->starts_at   ?: null,
            endsAt        : $row->ends_at     ?: null,
            description   : $row->description ?? null,
            createdAt     : (string) $row->created_at,
            updatedAt     : (string) $row->updated_at,
        );
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /** Human-readable discount string: "30٪" or "50,000 تومان" */
    public function discountLabel(): string
    {
        return $this->discountType === 'percent'
            ? number_format($this->discount) . '٪'
            : number_format($this->discount) . ' تومان';
    }

    /** Is this campaign currently live? */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Persian status label */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'active'    => 'فعال',
            'draft'     => 'پیش‌نویس',
            'scheduled' => 'زمان‌بندی شده',
            'ended'     => 'پایان یافته',
            default     => $this->status,
        };
    }

    /** CSS badge class for status */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'active'    => 'cmc-badge--active',
            'draft'     => 'cmc-badge--draft',
            'scheduled' => 'cmc-badge--info',
            'ended'     => 'cmc-badge--draft',
            default     => 'cmc-badge--draft',
        };
    }

    /** Type label */
    public function typeLabel(): string
    {
        return match ($this->type) {
            'flash_sale'    => 'فلش سیل',
            'amazing_offer' => 'پیشنهاد شگفت‌انگیز',
            default         => $this->type,
        };
    }

    /** Selection mode label (Persian) */
    public function selectionModeLabel(): string
    {
        return match ($this->selectionMode) {
            'manual'    => 'انتخاب دستی',
            'category'  => 'دسته‌بندی',
            'tag'       => 'برچسب',
            'attribute' => 'ویژگی',
            'brand'     => 'برند',
            'all'       => 'همه محصولات',
            default     => $this->selectionMode,
        };
    }
}