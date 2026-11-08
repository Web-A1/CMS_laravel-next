<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonMaterialKinescopeVideo extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'lesson_material_id',
        'embed_locator',
        'provider_video_id',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(LessonMaterial::class, 'lesson_material_id');
    }
}
