<?php

namespace App\Http\Resources;

use App\Models\ContentEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContentEntry */
class FaqResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->relationLoaded('category') ? $this->category : null;
        $subject = $this->relationLoaded('subject') ? $this->subject : null;

        return [
            'id' => $this->id,
            'subject' => $subject?->is_active ? ['id' => $subject->id, 'slug' => $subject->slug, 'title' => $subject->title] : null,
            'slug' => $this->slug,
            'question' => $this->title,
            'answer' => $this->body,
            'category' => $category ? [
                'slug' => $category->slug,
                'title' => $category->title,
            ] : null,
            'sortOrder' => (int) ($this->placement_sort_order ?? $this->sort_order),
            'publishedAt' => $this->published_at?->toIso8601String(),
        ];
    }
}
