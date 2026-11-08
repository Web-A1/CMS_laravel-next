<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPlacement extends Model
{
    use HasFactory;

    public const ARTICLES_INDEX = 'articles.index';

    public const ABOUT_PAGE = 'pages.about';

    public const FAQ_INDEX = 'faq.index';

    public const HOME_FAQ = 'home.faq';

    public const ALLOWED_PUBLIC_ARTICLE_PLACEMENTS = [
        self::ARTICLES_INDEX,
    ];

    public const ALLOWED_PUBLIC_FAQ_PLACEMENTS = [
        self::FAQ_INDEX,
        self::HOME_FAQ,
    ];

    protected $fillable = [
        'content_entry_id',
        'placement',
        'sort_order',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContentEntry::class, 'content_entry_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }
}
