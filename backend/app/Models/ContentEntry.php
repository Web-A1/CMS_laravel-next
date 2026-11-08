<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentEntry extends Model
{
    use HasFactory;

    public const TYPE_ARTICLE = 'article';

    public const TYPE_FAQ = 'faq';

    public const TYPE_PAGE = 'page';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'subject_id',
        'content_category_id',
        'content_type',
        'slug',
        'title',
        'body',
        'status',
        'sort_order',
        'payload',
        'seo_title',
        'seo_description',
        'published_at',
        'content_author_id', 'body_format', 'excerpt', 'cover_path', 'cover_alt',
        'cover_width', 'cover_height', 'tags', 'content_updated_at', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'payload' => 'array',
            'published_at' => 'datetime',
            'content_updated_at' => 'datetime',
            'tags' => 'array',
            'revision' => 'integer',
            'cover_width' => 'integer',
            'cover_height' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'content_category_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(ContentAuthor::class, 'content_author_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(ContentPlacement::class);
    }

    public function scopeFaq(Builder $query): Builder
    {
        return $query->where('content_type', self::TYPE_FAQ);
    }

    public function scopeArticle(Builder $query): Builder
    {
        return $query->where('content_type', self::TYPE_ARTICLE);
    }

    public function scopePage(Builder $query): Builder
    {
        return $query->where('content_type', self::TYPE_PAGE);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
