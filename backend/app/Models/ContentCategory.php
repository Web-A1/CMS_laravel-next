<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentCategory extends Model
{
    use HasFactory;

    public const TYPE_ARTICLE = 'article';

    public const TYPE_FAQ = 'faq';

    protected $fillable = [
        'content_type',
        'slug',
        'title',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ContentEntry::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFaq(Builder $query): Builder
    {
        return $query->where('content_type', self::TYPE_FAQ);
    }

    public function scopeArticle(Builder $query): Builder
    {
        return $query->where('content_type', self::TYPE_ARTICLE);
    }
}
