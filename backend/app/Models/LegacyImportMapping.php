<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LegacyImportMapping extends Model
{
    use HasFactory;

    public const MAPPING_BLOCKED = 'blocked';

    public const MAPPING_MAPPED = 'mapped';

    public const MAPPING_PENDING = 'pending';

    public const MAPPING_SKIPPED = 'skipped';

    public const SOURCE_SCOPE_ID_ONLY = 'id_only';

    public const SOURCE_SCOPE_LEGACY_PRODUCT = 'legacy_product';

    public const VALIDATION_FAILED = 'failed';

    public const VALIDATION_MANUAL_REVIEW = 'manual_review';

    public const VALIDATION_PASSED = 'passed';

    public const VALIDATION_PENDING = 'pending';

    protected $fillable = [
        'batch_key',
        'source_table',
        'source_id',
        'source_scope_type',
        'source_scope_id',
        'target_domain',
        'target_type',
        'target_id',
        'mapping_status',
        'validation_status',
        'idempotency_key',
        'source_fingerprint_hash',
        'manual_review_reason',
        'errors',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'metadata' => 'array',
        ];
    }

    public function scopeForBatch(Builder $query, string $batchKey): Builder
    {
        return $query->where('batch_key', $batchKey);
    }

    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('mapping_status', self::MAPPING_BLOCKED)
                ->orWhere('validation_status', self::VALIDATION_MANUAL_REVIEW);
        });
    }
}
