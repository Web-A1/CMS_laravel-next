<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEntitlementGrant extends Model
{
    use HasFactory;

    public const ATOM_GUIDE = 'guide';

    public const ATOM_LESSON = 'lesson';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REVOKED = 'revoked';

    public const SOURCE_ADMIN_GRANT = 'admin_grant';

    public const SOURCE_MIGRATION = 'migration';

    public const SOURCE_ORDER_ITEM = 'order_item';

    public const SOURCE_SUPPORT_ADJUSTMENT = 'support_adjustment';

    public const SOURCE_TEST_FIXTURE = 'test_fixture';

    public const ATOM_KINDS = [
        self::ATOM_LESSON,
        self::ATOM_GUIDE,
    ];

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PENDING,
        self::STATUS_REVOKED,
        self::STATUS_EXPIRED,
    ];

    public const SOURCE_TYPES = [
        self::SOURCE_ORDER_ITEM,
        self::SOURCE_MIGRATION,
        self::SOURCE_ADMIN_GRANT,
        self::SOURCE_SUPPORT_ADJUSTMENT,
        self::SOURCE_TEST_FIXTURE,
    ];

    protected $fillable = [
        'user_id',
        'atom_kind',
        'atom_id',
        'status',
        'source_type',
        'source_id',
        'order_id',
        'order_item_id',
        'idempotency_key',
        'granted_at',
        'revoked_at',
        'expires_at',
        'revoked_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeEffectiveActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function atomKey(): string
    {
        return self::atomKeyFor((string) $this->atom_kind, (int) $this->atom_id);
    }

    public static function atomKeyFor(string $kind, int $id): string
    {
        return "{$kind}:{$id}";
    }
}
