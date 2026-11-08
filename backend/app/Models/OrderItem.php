<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    public const ACCESS_GRANT_FAILED = 'grant_failed';

    public const ACCESS_GRANTED = 'granted';

    public const ACCESS_NOT_GRANTED = 'not_granted';

    public const ACCESS_PENDING_GRANT = 'pending_grant';

    public const KIND_BOX = 'box';

    public const KIND_COURSE = 'course';

    public const KIND_GUIDE = 'guide';

    public const KIND_INTENSIVE = 'intensive';

    public const KIND_SUBJECT = 'subject';

    public const KIND_LESSON = 'lesson';

    public const SELLABLE_KINDS = [
        self::KIND_LESSON,
        self::KIND_GUIDE,
        self::KIND_COURSE,
        self::KIND_INTENSIVE,
        self::KIND_SUBJECT,
        self::KIND_BOX,
    ];

    protected $fillable = [
        'order_id',
        'sellable_kind',
        'sellable_id',
        'sellable_sku',
        'purchased_entity_type',
        'purchased_entity_id',
        'purchased_entity_slug',
        'display_title',
        'quantity',
        'currency',
        'unit_amount',
        'subtotal_amount',
        'discount_amount',
        'final_amount',
        'price_id',
        'price_version',
        'snapshot_hash',
        'catalog_snapshot',
        'price_snapshot',
        'benefit_snapshot',
        'grant_snapshot',
        'access_status',
    ];

    protected function casts(): array
    {
        return [
            'catalog_snapshot' => 'array',
            'price_snapshot' => 'array',
            'benefit_snapshot' => 'array',
            'grant_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributesForSnapshot(): array
    {
        return [
            'sellable_kind' => $this->sellable_kind,
            'sellable_id' => $this->sellable_id,
            'sellable_sku' => $this->sellable_sku,
            'purchased_entity_type' => $this->purchased_entity_type,
            'purchased_entity_id' => $this->purchased_entity_id,
            'purchased_entity_slug' => $this->purchased_entity_slug,
            'display_title' => $this->display_title,
            'quantity' => $this->quantity,
            'currency' => $this->currency,
            'unit_amount' => $this->unit_amount,
            'subtotal_amount' => $this->subtotal_amount,
            'discount_amount' => $this->discount_amount,
            'final_amount' => $this->final_amount,
            'price_id' => $this->price_id,
            'price_version' => $this->price_version,
            'catalog_snapshot' => $this->catalog_snapshot,
            'price_snapshot' => $this->price_snapshot,
            'benefit_snapshot' => $this->benefit_snapshot,
            'grant_snapshot' => $this->grant_snapshot,
        ];
    }
}
