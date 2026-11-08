<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewEvidence extends Model
{
    use HasFactory;

    protected $table = 'review_evidence';

    protected $fillable = [
        'review_id',
        'review_source_id',
        'review_import_batch_id',
        'type',
        'file_path',
        'storage_url',
        'mime_type',
        'sha256',
        'is_public',
        'caption',
        'provenance',
        'captured_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'is_public' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReviewSource::class, 'review_source_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ReviewImportBatch::class, 'review_import_batch_id');
    }
}
