<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticleMediaAsset extends Model
{
    public $timestamps = false;

    protected $fillable = ['filename', 'disk', 'key', 'sha256', 'bytes', 'mime', 'origin', 'created_at', 'status', 'generation', 'processing_status', 'variants', 'width', 'height', 'lease_at', 'deleted_at', 'processing_error'];

    protected function casts(): array
    {
        return ['origin' => 'array', 'bytes' => 'integer', 'variants' => 'array', 'generation' => 'integer', 'width' => 'integer', 'height' => 'integer', 'lease_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $asset): void {
            foreach (['filename', 'disk', 'key', 'sha256', 'bytes', 'mime', 'origin', 'created_at'] as $field) {
                if ($asset->isDirty($field)) {
                    throw new \LogicException('Article media identity is immutable.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Article media registry cannot be deleted by the editor.'));
    }
}
