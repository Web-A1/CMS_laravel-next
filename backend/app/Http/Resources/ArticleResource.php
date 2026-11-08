<?php

namespace App\Http\Resources;

use App\Models\ContentEntry;
use App\Services\Articles\ArticleMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContentEntry */
class ArticleResource extends JsonResource
{
    private bool $includeBody = false;

    public function withBody(): static
    {
        $this->includeBody = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $category = $this->relationLoaded('category') ? $this->category : null;
        $subject = $this->relationLoaded('subject') ? $this->subject : null;
        $author = $this->relationLoaded('author') ? $this->author : null;
        $media = app(ArticleMedia::class);

        return [
            'id' => $this->id,
            'subject' => $subject?->is_active ? ['id' => $subject->id, 'slug' => $subject->slug, 'title' => $subject->title] : null,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->seo_description ?: $this->excerpt,
            'excerpt' => $this->excerpt,
            'updatedAt' => $this->content_updated_at?->toIso8601String(),
            'author' => $author ? (new ContentAuthorResource($author))->resolve($request) : null,
            'cover' => $this->cover_path ? ['url' => $media->publicUrl($this->cover_path), 'variants' => $media->variants($this->cover_path), 'alt' => $this->cover_alt ?? '', 'width' => $this->cover_width ? (int) $this->cover_width : null, 'height' => $this->cover_height ? (int) $this->cover_height : null] : null,
            // Retained as an empty compatibility field; articles are classified by subject.
            'tags' => [],
            'body' => $this->when($this->includeBody, fn () => $this->body_format === 'html' ? $media->publicHtml($this->body) : $this->body),
            'bodyFormat' => $this->when($this->includeBody, $this->body_format),
            'attachments' => $this->when($this->includeBody, fn () => array_map(fn ($attachment) => array_replace($attachment, ['url' => $media->publicUrl(preg_replace('~^/storage/~', '', $attachment['url']))]), $this->payload['attachments'] ?? [])),
            'related' => $this->when($this->includeBody, fn () => ArticleResource::collection($this->relationLoaded('related') ? $this->related : collect())),
            'relatedFaqs' => $this->when($this->includeBody, fn () => FaqResource::collection($this->relationLoaded('relatedFaqs') ? $this->relatedFaqs : collect())),
            'category' => $category ? [
                'slug' => $category->slug,
                'title' => $category->title,
            ] : null,
            'sortOrder' => (int) ($this->placement_sort_order ?? $this->sort_order),
            'publishedAt' => $this->published_at?->toIso8601String(),
            'seoTitle' => $this->when($this->includeBody, $this->seo_title),
            'seoDescription' => $this->when($this->includeBody, $this->seo_description),
        ];
    }
}
