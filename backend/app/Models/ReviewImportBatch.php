<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewImportBatch extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'source_type',
        'batch_ref',
        'source_label',
        'source_path',
        'source_url',
        'imported_by',
        'status',
        'started_at',
        'finished_at',
        'records_total',
        'records_created',
        'records_updated',
        'stats',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
            'errors' => 'array',
        ];
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'created_from_import_batch_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ReviewSource::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ReviewEvidence::class);
    }
}
