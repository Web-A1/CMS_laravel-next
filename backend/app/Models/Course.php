<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'slug',
        'title',
        'short_title',
        'price_cents',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function activeSellableEntity(): HasOne
    {
        return $this->hasOne(SellableEntity::class, 'entity_id')
            ->where('entity_type', OrderItem::KIND_COURSE)
            ->where('sellable_kind', OrderItem::KIND_COURSE)
            ->active()
            ->ofMany(['id' => 'min'], fn ($query) => $query
                ->where('entity_type', OrderItem::KIND_COURSE)
                ->where('sellable_kind', OrderItem::KIND_COURSE)
                ->active());
    }

    public function itemId(): string
    {
        $subjectSlug = $this->relationLoaded('subject') && $this->subject
            ? $this->subject->slug
            : (string) $this->subject()->value('slug');

        return "course:{$subjectSlug}:{$this->slug}";
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
