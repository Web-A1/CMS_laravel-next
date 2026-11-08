<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyProductMapping extends Model
{
    use HasFactory;

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MAPPED = 'mapped';

    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'legacy_product_id',
        'legacy_buyaction_id',
        'sellable_entity_id',
        'mapping_status',
        'legacy_payload_hash',
        'grant_snapshot_version',
        'manual_review_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'legacy_product_id' => 'integer',
            'legacy_buyaction_id' => 'integer',
            'grant_snapshot_version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function sellableEntity(): BelongsTo
    {
        return $this->belongsTo(SellableEntity::class);
    }
}
