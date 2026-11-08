<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Review extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const MODERATION_PENDING = 'pending';

    public const MODERATION_NEEDS_REVIEW = 'needs_review';

    public const MODERATION_APPROVED = 'approved';

    public const MODERATION_REJECTED = 'rejected';

    public const PUBLICATION_DRAFT = 'draft';

    public const PUBLICATION_PUBLISHED = 'published';

    public const PUBLICATION_HIDDEN = 'hidden';

    public const PUBLICATION_ARCHIVED = 'archived';

    public const DEDUPE_UNCHECKED = 'unchecked';

    public const DEDUPE_UNIQUE = 'unique';

    public const DEDUPE_CANDIDATE = 'candidate';

    public const DEDUPE_DUPLICATE = 'duplicate';

    public const ORIGIN_PLATFORM = 'platform';

    public const ORIGIN_LEGACY_IMPORT = 'legacy_import';

    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_DEV_SEED = 'dev_seed';

    protected $fillable = [
        'course_id',
        'subject_id',
        'user_id',
        'text',
        'display_name',
        'author_photo_path',
        'show_on_home',
        'media_managed',
        'content_edited_at',
        'is_anonymous',
        'status',
        'moderation_status',
        'publication_status',
        'dedupe_status',
        'origin_type',
        'created_from_import_batch_id',
        'source',
        'source_ref',
        'review_written_at',
        'published_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'show_on_home' => 'boolean',
            'media_managed' => 'boolean',
            'content_edited_at' => 'datetime',
            'review_written_at' => 'datetime',
            'published_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Review $review): void {
            $review->applyWorkflowDefaults();
        });
    }

    public function applyWorkflowDefaults(): void
    {
        if (! $this->moderation_status) {
            $this->moderation_status = match ($this->status) {
                self::STATUS_PUBLISHED, self::STATUS_REVIEWED => self::MODERATION_APPROVED,
                self::STATUS_REJECTED => self::MODERATION_REJECTED,
                default => self::MODERATION_PENDING,
            };
        }

        if (! $this->publication_status) {
            $this->publication_status = match ($this->status) {
                self::STATUS_PUBLISHED => self::PUBLICATION_PUBLISHED,
                self::STATUS_REJECTED => self::PUBLICATION_ARCHIVED,
                default => self::PUBLICATION_DRAFT,
            };
        }

        if (! $this->dedupe_status) {
            $this->dedupe_status = self::DEDUPE_UNCHECKED;
        }

        if (! $this->origin_type) {
            $this->origin_type = match ($this->source) {
                'student' => self::ORIGIN_PLATFORM,
                'admin', 'legacy_manual' => self::ORIGIN_MANUAL,
                'dev_seed' => self::ORIGIN_DEV_SEED,
                default => self::ORIGIN_LEGACY_IMPORT,
            };
        }
    }

    public function authorPhotoUrl(): ?string
    {
        if ($this->is_anonymous || ! $this->author_photo_path) {
            return null;
        }

        return $this->imageAsset($this->author_photo_path)?->url() ?? Storage::disk('public')->url($this->author_photo_path);
    }

    public function imageAssets(): HasMany
    {
        return $this->hasMany(ReviewImageAsset::class);
    }

    public function imageAsset(string $path): ?ReviewImageAsset
    {
        return $this->imageAssets->first(fn (ReviewImageAsset $asset): bool => $asset->primary_path === $path && $asset->status === 'ready');
    }

    public function publicScopeLabel(): string
    {
        if ($this->relationLoaded('course') && $this->course) {
            $courseTitle = $this->course->short_title ?: $this->course->title;

            if ($this->course->relationLoaded('subject') && $this->course->subject) {
                return trim($this->course->subject->title.' · '.$courseTitle);
            }

            return $courseTitle;
        }

        if ($this->relationLoaded('subject') && $this->subject) {
            return $this->subject->title;
        }

        return 'Все';
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ReviewMedia::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ReviewImportBatch::class, 'created_from_import_batch_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ReviewSource::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ReviewEvidence::class);
    }

    public function duplicateCandidates(): HasMany
    {
        return $this->hasMany(ReviewDuplicateCandidate::class);
    }

    public function moderationEvents(): HasMany
    {
        return $this->hasMany(ReviewModerationEvent::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->where('publication_status', self::PUBLICATION_PUBLISHED)
                    ->orWhere(function (Builder $legacyQuery): void {
                        $legacyQuery
                            ->whereNull('publication_status')
                            ->where('status', self::STATUS_PUBLISHED);
                    });
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('moderation_status', self::MODERATION_APPROVED)
                    ->orWhereNull('moderation_status');
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('dedupe_status')
                    ->orWhere('dedupe_status', '!=', self::DEDUPE_DUPLICATE);
            })
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
