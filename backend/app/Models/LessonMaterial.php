<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LessonMaterial extends Model
{
    use HasFactory;

    public const KIND_VIDEO = 'video';

    public const KIND_PDF = 'pdf';

    public const KIND_NOTES = 'notes';

    public const KIND_FILE = 'file';

    public const KINDS = [
        self::KIND_VIDEO,
        self::KIND_PDF,
        self::KIND_NOTES,
        self::KIND_FILE,
    ];

    public $timestamps = false;

    protected $attributes = [
        'sort_order' => 0,
        'is_published' => false,
    ];

    protected $fillable = [
        'lesson_id',
        'guide_id',
        'kind',
        'title',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class, 'guide_id');
    }

    public function kinescopeVideo(): HasOne
    {
        return $this->hasOne(LessonMaterialKinescopeVideo::class, 'lesson_material_id');
    }

    public function protectedFile(): HasOne
    {
        return $this->hasOne(LessonMaterialFile::class, 'lesson_material_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
