<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ReviewImageAsset extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['variants' => 'array', 'width' => 'integer', 'height' => 'integer'];
    }

    public function url(?string $path = null): string
    {
        $path ??= $this->primary_path;

        return $this->disk === 'public' ? '/storage/'.$path : Storage::disk($this->disk)->url($path);
    }

    public function publicVariants(): array
    {
        if ($this->status !== 'ready') {
            return [];
        }

        return array_map(fn (array $variant): array => [
            'url' => $this->url($variant['path']), 'format' => $variant['format'],
            'width' => $variant['width'], 'height' => $variant['height'],
        ], $this->variants ?? []);
    }
}
