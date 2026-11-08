<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'slug',
        'title',
        'sort_order',
        'is_active',
        'is_free_preview',
        'legacy_product_id',
        'legacy_url',
        'legacy_price_cents',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_free_preview' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function learningContent(): HasOne
    {
        return $this->hasOne(LessonLearningContent::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(LessonMaterial::class);
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(LessonAssessment::class);
    }

    public function assessmentCompletions(): HasMany
    {
        return $this->hasMany(LessonAssessmentCompletion::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
