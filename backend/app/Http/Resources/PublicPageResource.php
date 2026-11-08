<?php

namespace App\Http\Resources;

use App\Models\ContentEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContentEntry */
class PublicPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->seo_description,
            'body' => $this->body,
            'sortOrder' => (int) ($this->placement_sort_order ?? $this->sort_order),
            'publishedAt' => $this->published_at?->toIso8601String(),
            'seoTitle' => $this->seo_title,
            'seoDescription' => $this->seo_description,
        ];
    }
}
