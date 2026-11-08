<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonAssessmentAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_assessment_version_id',
        'asset_key',
        'disk',
        'object_key',
        'sha256',
        'mime_type',
        'width',
        'height',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LessonAssessmentVersion::class, 'lesson_assessment_version_id');
    }
}
