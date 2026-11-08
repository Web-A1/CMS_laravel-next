<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LegacyRouteMapping extends Model
{
    use HasFactory;

    public const ACTION_DIRECT = 'direct';

    public const ACTION_DROP = 'drop';

    public const ACTION_MANUAL_REVIEW = 'manual_review';

    public const ACTION_MERGE = 'merge';

    public const ACTION_REDIRECT = 'redirect';

    public const KIND_ADMIN = 'admin';

    public const KIND_LEARNING = 'learning';

    public const KIND_PUBLIC = 'public';

    public const KIND_SERVICE = 'service';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MAPPED = 'mapped';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const SURFACE_ADMIN = 'admin';

    public const SURFACE_LK = 'lk';

    public const SURFACE_PLATFORM = 'platform';

    public const SURFACE_PUBLIC = 'public';

    protected $fillable = [
        'legacy_path',
        'target_path',
        'source_surface',
        'route_kind',
        'action',
        'parity_status',
        'requires_auth',
        'manual_review_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'requires_auth' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function scopeForSurface(Builder $query, string $surface): Builder
    {
        return $query->where('source_surface', $surface);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('parity_status', self::STATUS_PENDING);
    }
}
