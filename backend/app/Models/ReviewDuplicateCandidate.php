<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewDuplicateCandidate extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED_DUPLICATE = 'confirmed_duplicate';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_MERGED = 'merged';

    protected $fillable = [
        'review_id',
        'candidate_review_id',
        'candidate_review_source_id',
        'similarity_score',
        'match_type',
        'status',
        'reason',
        'signals',
        'reviewed_by',
        'reviewed_at',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'similarity_score' => 'decimal:4',
            'signals' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function candidateReview(): BelongsTo
    {
        return $this->belongsTo(Review::class, 'candidate_review_id');
    }

    public function candidateSource(): BelongsTo
    {
        return $this->belongsTo(ReviewSource::class, 'candidate_review_source_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
