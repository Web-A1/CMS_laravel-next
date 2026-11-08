<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewSource extends Model
{
    use HasFactory;

    public const TYPE_PLATFORM = 'platform';

    public const TYPE_TELEGRAM = 'telegram';

    public const TYPE_VK_WEBARCHIVE = 'vk_webarchive';

    public const TYPE_YANDEX_MAPS_WEBARCHIVE = 'yandex_maps_webarchive';

    public const TYPE_LEGACY_SITE = 'legacy_site';

    public const TYPE_MANUAL = 'manual';

    protected $fillable = [
        'review_id',
        'review_import_batch_id',
        'source_type',
        'source_ref',
        'source_label',
        'source_url',
        'is_public',
        'external_id',
        'external_author_name',
        'external_written_at',
        'captured_at',
        'raw_text',
        'raw_payload',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'external_written_at' => 'datetime',
            'captured_at' => 'datetime',
            'is_public' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ReviewImportBatch::class, 'review_import_batch_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ReviewEvidence::class);
    }

    public function hasPublicUrl(): bool
    {
        return $this->is_public && filled($this->source_url);
    }
}
