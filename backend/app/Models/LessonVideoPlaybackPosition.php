<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonVideoPlaybackPosition extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'lesson_material_id',
        'position_milliseconds',
        'duration_milliseconds',
    ];

    protected function casts(): array
    {
        return [
            'position_milliseconds' => 'integer',
            'duration_milliseconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(LessonMaterial::class, 'lesson_material_id');
    }
}
