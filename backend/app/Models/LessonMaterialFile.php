<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonMaterialFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_material_id',
        'source_object_key',
        'source_sha256',
        'source_size_bytes',
        'source_mime_type',
        'download_filename',
    ];

    protected $hidden = [
        'source_object_key',
        'source_sha256',
    ];

    protected function casts(): array
    {
        return [
            'source_size_bytes' => 'integer',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(LessonMaterial::class, 'lesson_material_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ProtectedFileDelivery::class, 'lesson_material_file_id');
    }
}
