<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellableEntity extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_DRAFT = 'draft';

    protected $fillable = [
        'sellable_kind',
        'entity_type',
        'entity_id',
        'sku',
        'title',
        'currency',
        'price_cents',
        'price_version',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'price_cents' => 'integer',
            'price_version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function grantRules(): HasMany
    {
        return $this->hasMany(SellableGrantRule::class);
    }

    public function legacyProductMappings(): HasMany
    {
        return $this->hasMany(LegacyProductMapping::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
